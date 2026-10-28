<?php

namespace App\Services;

use App\Models\AcademicSupporter;
use App\Models\AcademicSupporterGalleryItem;
use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use App\Notifications\PasswordResetByAdmin;
use App\Traits\LogsAuditEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * الداعم الأكاديمي كيان منفصل تماماً عن Teacher — لا مرحلة توثيق/وثائق، الأدمن
 * يضيف الحساب فيصير نشطاً فوراً (بعكس TeacherService::createByAdmin الذي يبدأ
 * بـ active_unverified بانتظار استكمال الملف ورفع الوثائق).
 */
class AcademicSupporterService
{
    use LogsAuditEvents;

    public function __construct(private readonly NotificationService $notifications) {}

    public function createByAdmin(array $data, User $admin, ?int $notificationDelaySeconds = null): AcademicSupporter
    {
        return DB::transaction(function () use ($data, $admin, $notificationDelaySeconds) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'academic_support',
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $supporter = AcademicSupporter::create([
                'user_id' => $user->id,
                'status' => 'active',
            ]);

            $this->notifications->send(
                $user,
                new AccountCreatedByAdmin('academic_support', $data['password']),
                'academic_supporter.account_created',
                $notificationDelaySeconds,
            );

            $this->audit('academic_supporter.account_created', $supporter, [], ['email' => $user->email], null, $admin->id);

            return $supporter;
        });
    }

    public function updateProfile(AcademicSupporter $supporter, array $data): AcademicSupporter
    {
        $subjectIds = $data['subject_ids'] ?? null;
        $languageIds = $data['language_ids'] ?? null;
        unset($data['subject_ids'], $data['language_ids']);

        $supporter->fill($data);
        $supporter->save();

        if ($subjectIds !== null) {
            $supporter->subjects()->sync($subjectIds);
        }

        if ($languageIds !== null) {
            $supporter->languages()->sync($languageIds);
        }

        return $supporter->fresh(['subjects', 'languages', 'university', 'major', 'user']);
    }

    /**
     * $thumbnail اختيارية يرفعها الداعم يدوياً (راجع تعليق هجرة thumbnail_path) —
     * بلا توليد تلقائي، لعدم توفر محرّك تحويل PPTX/XLSX على السيرفر حالياً.
     */
    public function addGalleryItem(AcademicSupporter $supporter, $file, $thumbnail = null): AcademicSupporterGalleryItem
    {
        if ($supporter->galleryItems()->count() >= AcademicSupporter::MAX_GALLERY_ITEMS) {
            throw ValidationException::withMessages([
                'gallery' => ['لا يمكن إضافة أكثر من '.AcademicSupporter::MAX_GALLERY_ITEMS.' عنصراً — احذف عنصراً قديماً أولاً.'],
            ]);
        }

        $path = FileStorage::storeFile($file, "academic_supporter_gallery/{$supporter->id}", 'gallery', 'public');
        $thumbnailPath = $thumbnail
            ? FileStorage::storeFile($thumbnail, "academic_supporter_gallery/{$supporter->id}/thumbnails", 'img', 'public')
            : null;

        $extension = strtolower($file->getClientOriginalExtension());
        $fileType = match (true) {
            in_array($extension, ['jpeg', 'jpg', 'png'], true) => 'image',
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['ppt', 'pptx'], true) => 'pptx',
            in_array($extension, ['xls', 'xlsx'], true) => 'xlsx',
            in_array($extension, ['doc', 'docx'], true) => 'docx',
            default => 'pdf',
        };

        $item = $supporter->galleryItems()->create([
            'file_path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'file_type' => $fileType,
            'sort_order' => ($supporter->galleryItems()->max('sort_order') ?? -1) + 1,
        ]);

        $this->audit('academic_supporter.gallery_item_added', $item, [], ['original_name' => $item->original_name]);

        return $item;
    }

    public function removeGalleryItem(AcademicSupporterGalleryItem $item): void
    {
        $this->audit('academic_supporter.gallery_item_removed', $item, ['original_name' => $item->original_name], []);

        // file_path محفوظ كرابط عام كامل (نفس عائد FileStorage::storeFile لقرص public) —
        // نفس نمط AuthService::deleteAvatar/TeacherService::removeGalleryItem بالضبط.
        Storage::disk('public')->delete(Str::after($item->file_path, '/storage/'));

        $item->delete();
    }

    public function suspend(AcademicSupporter $supporter, User $admin, string $reason): AcademicSupporter
    {
        $old = $supporter->only(['status']);

        $supporter->update(['status' => 'suspended']);

        $supporter->loadMissing('user');
        $supporter->user?->tokens()->delete();

        $this->audit('academic_supporter.suspended', $supporter, $old, ['status' => 'suspended'], $reason, $admin->id);

        return $supporter;
    }

    public function reactivate(AcademicSupporter $supporter, User $admin): AcademicSupporter
    {
        $old = $supporter->only(['status']);

        $supporter->update(['status' => 'active']);

        $this->audit('academic_supporter.reactivated', $supporter, $old, ['status' => 'active'], null, $admin->id);

        return $supporter;
    }

    public function resetPasswordByAdmin(AcademicSupporter $supporter, string $newPassword, User $admin): void
    {
        $supporter->loadMissing('user');
        $user = $supporter->user;

        $user->update(['password' => $newPassword]);
        $user->tokens()->delete();

        $this->notifications->send($user, new PasswordResetByAdmin($newPassword), 'academic_supporter.password_reset_by_admin');

        $this->audit('academic_supporter.password_reset_by_admin', $supporter, [], [], null, $admin->id);
    }

    /**
     * حذف حقيقي (لا تعليق ولا soft delete) — نظير TeacherService::deleteTeacher
     * بالضبط. academic_supporters.user_id بقيد cascadeOnDelete فحذف المستخدم
     * يكفي لحذف صف الداعم وكل ما يتفرّع منه (غاليري، مواد/لغات) تلقائياً على
     * مستوى القاعدة؛ restrictOnDelete على support_package_purchases يبقى شبكة
     * أمان أخيرة — لو كان للداعم عمليات شراء فعلية يفشل الحذف بدل أن يمحو سجلاً مالياً.
     */
    public function deleteAcademicSupporter(AcademicSupporter $supporter, User $admin): void
    {
        $supporter->loadMissing('user');
        $user = $supporter->user;
        $oldValues = $supporter->only(['status']);

        try {
            DB::transaction(function () use ($user) {
                $user?->tokens()->delete();
                $user?->forceDelete();
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف هذا الداعم الأكاديمي لوجود عمليات شراء مسجَّلة — علّق حسابه بدلاً من ذلك.'],
            ]);
        }

        $this->audit('academic_supporter.deleted', $supporter, $oldValues, [], null, $admin->id);
    }
}
