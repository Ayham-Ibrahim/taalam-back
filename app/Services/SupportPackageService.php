<?php

namespace App\Services;

use App\Models\SupportPackage;
use App\Models\Teacher;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

/**
 * باقات الدعم الطلابي — مقصورة على الداعم الأكاديمي (Teacher::isAcademicSupport).
 * بعكس PackageService: لا مرحلة draft (نموذج واحد يُرسَل مباشرة pending_approval)،
 * ولا هامش منصة إطلاقاً (platform_margin_percent=0 ثابتة، السعر نفسه ثابت حسب
 * الفئة من الإعدادات — لا يتحكم به الداعم ولا الأدمن). راجع تعليق هجرة الجدول.
 */
class SupportPackageService
{
    use LogsAuditEvents;

    public function __construct(private readonly SettingsService $settings) {}

    public function create(Teacher $teacher, array $data): SupportPackage
    {
        if (! $teacher->isAcademicSupport()) {
            throw ValidationException::withMessages([
                'teacher_type' => ['باقات الدعم الطلابي مخصصة للداعم الأكاديمي فقط'],
            ]);
        }

        if (! $teacher->isVerified()) {
            throw ValidationException::withMessages([
                'teacher_status' => ['يجب توثيق الحساب أولاً قبل إنشاء باقة دعم'],
            ]);
        }

        $price = $this->priceForTier($data['tier']);

        $supportPackage = SupportPackage::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $data['subject_id'],
            'tier' => $data['tier'],
            'description' => $data['description'],
            'teacher_price' => $price,
            'platform_margin_percent' => 0,
            'student_price' => $price,
            'platform_revenue' => 0,
            'status' => 'pending_approval',
            'submitted_at' => now(),
        ]);

        $this->audit('support_package.submitted', $supportPackage, [], ['status' => 'pending_approval']);

        return $supportPackage->fresh(['subject']);
    }

    private function priceForTier(string $tier): float
    {
        $key = $tier === 'premium' ? 'support_package_premium_price' : 'support_package_basic_price';
        $default = $tier === 'premium' ? 1500 : 1000;

        return (float) $this->settings->get($key, $default);
    }

    public function approve(SupportPackage $supportPackage, User $admin): SupportPackage
    {
        if ($supportPackage->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الموافقة إلا على باقة بانتظار المراجعة'],
            ]);
        }

        $supportPackage->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'rejection_reason' => null,
        ]);

        $this->audit('support_package.approved', $supportPackage, [], ['status' => 'active'], null, $admin->id);

        return $supportPackage;
    }

    public function reject(SupportPackage $supportPackage, User $admin, string $reason): SupportPackage
    {
        if ($supportPackage->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الرفض إلا لباقة بانتظار المراجعة'],
            ]);
        }

        $supportPackage->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $this->audit('support_package.rejected', $supportPackage, [], ['status' => 'rejected'], $reason, $admin->id);

        return $supportPackage;
    }

    /**
     * لا فحص جلسات/اشتراكات نشطة هنا (بعكس PackageService::disable) — لا جلسات
     * أصلاً لهذا النوع. Phase 2 (عند بناء الشراء الفعلي) يجب أن يضيف فحصاً مماثلاً
     * لمنع التعطيل إن وُجد طلاب مشتركون قائمون بالباقة.
     */
    public function disable(SupportPackage $supportPackage): SupportPackage
    {
        if ($supportPackage->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعطيل الباقة من هذه الحالة'],
            ]);
        }

        $supportPackage->update(['status' => 'disabled']);

        $this->audit('support_package.disabled', $supportPackage);

        return $supportPackage;
    }

    public function enable(SupportPackage $supportPackage): SupportPackage
    {
        if ($supportPackage->status !== 'disabled') {
            throw ValidationException::withMessages([
                'status' => ['يمكن إعادة التفعيل فقط لباقة معطّلة'],
            ]);
        }

        $supportPackage->update(['status' => 'active']);

        $this->audit('support_package.enabled', $supportPackage);

        return $supportPackage;
    }
}
