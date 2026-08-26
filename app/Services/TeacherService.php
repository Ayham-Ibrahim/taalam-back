<?php

namespace App\Services;

use App\Models\AccountInvitation;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use App\Notifications\TeacherInvited;
use App\Notifications\TeacherVerificationReviewed;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherService
{
    use LogsAuditEvents;

    /** لا يكتمل التسجيل بدونها — إثبات هوية، شهادة أكاديمية، وشهادة/إثبات خبرة */
    private const REQUIRED_DOCUMENT_TYPES = ['identity', 'academic', 'experience'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * RULE-01: لا تسجيل ذاتي للمعلمين — الأدمن فقط ينشئ الحساب ويدعو صاحبه لتفعيله.
     */
    public function invite(array $data, User $admin): Teacher
    {
        return DB::transaction(function () use ($data, $admin) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'teacher',
                'password' => null,
            ]);

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'teacher_type' => $data['teacher_type'],
                'status' => 'invited',
            ]);

            $invitation = AccountInvitation::create([
                'user_id' => $user->id,
                'invited_by' => $admin->id,
                'token' => Str::random(60),
                'expires_at' => now()->addHours((int) $this->settings->get('invitation_link_expiry_hours', 48)),
            ]);

            $this->notifications->send($user, new TeacherInvited($invitation), 'teacher.invited');

            $this->audit('teacher.invited', $teacher, [], [
                'teacher_type' => $teacher->teacher_type,
                'email' => $user->email,
            ]);

            return $teacher;
        });
    }

    /**
     * دخول فوري بلا خطوة قبول دعوة — الأدمن يضع كلمة المرور مباشرة، الحساب
     * نشط لحظياً بحالة active_unverified (نفس نقطة انطلاق acceptInvitation)
     * فيرى المعلم عند أول دخول واجهة إكمال الملف + رفع الوثائق.
     */
    public function createByAdmin(array $data, User $admin): Teacher
    {
        return DB::transaction(function () use ($data, $admin) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'teacher',
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'teacher_type' => $data['teacher_type'],
                'status' => 'active_unverified',
            ]);

            $this->notifications->send($user, new AccountCreatedByAdmin('teacher', $data['password']), 'teacher.account_created');

            $this->audit('teacher.account_created', $teacher, [], [
                'teacher_type' => $teacher->teacher_type,
                'email' => $user->email,
            ]);

            return $teacher;
        });
    }

    public function acceptInvitation(string $token, string $password): array
    {
        $invitation = AccountInvitation::with('user.teacher')->where('token', $token)->first();

        if (! $invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['رابط الدعوة غير صالح أو منتهي الصلاحية'],
            ]);
        }

        return DB::transaction(function () use ($invitation, $password) {
            $user = $invitation->user;
            $user->update(['password' => $password, 'is_active' => true]);

            $teacher = $user->teacher;
            $teacher->update(['status' => 'active_unverified']);

            $invitation->update(['accepted_at' => now()]);

            $this->audit('teacher.invitation_accepted', $teacher);

            return [
                'user' => $user,
                'teacher' => $teacher,
                'token' => $user->createToken('api')->plainTextToken,
            ];
        });
    }

    public function updateProfile(Teacher $teacher, array $data): Teacher
    {
        $subjectIds = $data['subject_ids'] ?? null;
        $curriculumIds = $data['curriculum_ids'] ?? null;
        $languageIds = $data['language_ids'] ?? null;

        unset($data['subject_ids'], $data['curriculum_ids'], $data['language_ids']);

        $teacher->fill($data);
        $teacher->save();

        if ($subjectIds !== null) {
            $teacher->subjects()->sync($subjectIds);
        }

        if ($curriculumIds !== null) {
            $teacher->curricula()->sync($curriculumIds);
        }

        if ($languageIds !== null) {
            $teacher->languages()->sync($languageIds);
        }

        return $teacher->fresh(['subjects', 'curricula', 'languages']);
    }

    public function submitForVerification(Teacher $teacher): Teacher
    {
        if ($teacher->status !== 'active_unverified') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن إرسال طلب التوثيق من هذه الحالة'],
            ]);
        }

        $uploadedTypes = $teacher->verificationDocuments()->pluck('type')->unique()->all();
        $missingTypes = array_diff(self::REQUIRED_DOCUMENT_TYPES, $uploadedTypes);

        if (! $teacher->bio || ! empty($missingTypes)) {
            throw ValidationException::withMessages([
                'profile' => ['أكمل الملف الشخصي وارفع الوثائق المطلوبة (إثبات الهوية، الشهادة الأكاديمية، وشهادة الخبرة) قبل الإرسال للمراجعة'],
            ]);
        }

        $teacher->update(['status' => 'pending_verification']);

        $this->audit('teacher.submitted_for_verification', $teacher);

        return $teacher;
    }

    public function approve(Teacher $teacher, User $admin): Teacher
    {
        if ($teacher->status !== 'pending_verification') {
            throw ValidationException::withMessages([
                'status' => ['يمكن التوثيق فقط من حالة قيد المراجعة'],
            ]);
        }

        $old = $teacher->only(['status']);

        $teacher->update([
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $admin->id,
            'rejection_reason' => null,
        ]);

        $teacher->loadMissing('user');
        $this->notifications->send($teacher->user, new TeacherVerificationReviewed(true), 'teacher.verified');

        $this->audit('teacher.verified', $teacher, $old, ['status' => 'verified']);

        return $teacher;
    }

    public function reject(Teacher $teacher, User $admin, string $reason): Teacher
    {
        $old = $teacher->only(['status']);

        $teacher->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $teacher->loadMissing('user');
        $this->notifications->send($teacher->user, new TeacherVerificationReviewed(false, $reason), 'teacher.rejected');

        $this->audit('teacher.rejected', $teacher, $old, ['status' => 'rejected'], $reason);

        return $teacher;
    }

    public function suspend(Teacher $teacher, User $admin, string $reason): Teacher
    {
        $old = $teacher->only(['status']);

        $teacher->update(['status' => 'suspended']);

        $this->audit('teacher.suspended', $teacher, $old, ['status' => 'suspended'], $reason);

        return $teacher;
    }

    /**
     * التعليق يُطبَّق فقط على معلم موثَّق مسبقاً (status='verified' حصراً) —
     * فإعادة التفعيل تعيده لتلك الحالة تحديداً، لا عبر approve() التي تشترط
     * status='pending_verification' (خطأ سابق: الفرونت إند كان يستدعي approve()
     * كإجراء "إعادة تفعيل" فيُرفض دائماً لمعلم suspended فعلياً).
     */
    public function reactivate(Teacher $teacher, User $admin): Teacher
    {
        if ($teacher->status !== 'suspended') {
            throw ValidationException::withMessages([
                'status' => ['يمكن إعادة التفعيل فقط لحساب موقوف'],
            ]);
        }

        $old = $teacher->only(['status']);

        $teacher->update(['status' => 'verified']);

        $this->audit('teacher.reactivated', $teacher, $old, ['status' => 'verified']);

        return $teacher;
    }

    /**
     * إحصائيات لوحة المعلم/المركز. rating_avg/reviews_count أعمدة محدَّثة تلقائياً
     * (راجع ReviewObserver) — لا تُعاد حسابتهما هنا. teaching_hours وtotal_students
     * محسوبان مباشرة إذ لا عمود مُخزَّن يعكسهما بدقة.
     */
    public function getStats(Teacher $teacher): array
    {
        $teachingMinutes = ClassSession::where('teacher_id', $teacher->id)
            ->where('status', 'completed')
            ->sum('duration_min');

        $studentIds = Booking::where('teacher_id', $teacher->id)->whereNotNull('student_id')->pluck('student_id')
            ->merge(Enrollment::where('teacher_id', $teacher->id)->whereNotNull('student_id')->pluck('student_id'))
            ->unique();

        return [
            'rating_avg' => (float) $teacher->rating_avg,
            'reviews_count' => $teacher->reviews_count,
            'teaching_hours' => round($teachingMinutes / 60, 1),
            'total_students' => $studentIds->count(),
        ];
    }
}
