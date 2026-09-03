<?php

namespace App\Services;

use App\Models\AccountInvitation;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Notifications\AccountCreatedByAdmin;
use App\Notifications\PasswordResetByAdmin;
use App\Notifications\TeacherInvited;
use App\Notifications\TeacherVerificationReviewed;
use App\Traits\LogsAuditEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * RULE-01: لا تسجيل ذاتي للمعلمين — الأدمن فقط ينشئ الحساب ويدعو صاحبه لتفعيله.
     */
    public function invite(array $data, User $admin, ?int $notificationDelaySeconds = null): Teacher
    {
        return DB::transaction(function () use ($data, $admin, $notificationDelaySeconds) {
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

            $this->notifications->send($user, new TeacherInvited($invitation), 'teacher.invited', $notificationDelaySeconds);

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

    /**
     * يوازي StudentService::resetPasswordByAdmin مع استثناء واحد: الطالب ليس له
     * حالة "invited" (لا خطوة دعوة له أصلاً)، أما المعلم المستورَد/المدعو
     * (invite()) فحسابه بلا كلمة مرور حتى يقبل الدعوة بنفسه؛ لو استخدم الأدمن
     * هذه الدالة عليه مباشرة بدل ذلك (لتفعيله فوراً بلا انتظار قبوله للدعوة)
     * وبقيت الحالة invited، تبقى شاشة "أكمل ملفك الشخصي" (CompleteTeacherProfilePage)
     * تخفي نموذج البيانات الشخصية بالكامل (مقيَّد بـ status === 'active_unverified'
     * فقط) — فيدخل المعلم برقم مرور فعّال لكن بلا أي طريق لرؤية نبذته المستورَدة
     * أو تعديلها أو حتى إرسال طلبه للمراجعة، عالق نهائياً. لذا نطبّق هنا نفس
     * انتقال الحالة الذي تُطبّقه acceptInvitation()/createByAdmin() عند التفعيل.
     */
    public function resetPasswordByAdmin(Teacher $teacher, string $newPassword, User $admin): void
    {
        $teacher->loadMissing('user');
        $user = $teacher->user;

        $user->update(['password' => $newPassword]);
        $user->tokens()->delete();

        if ($teacher->status === 'invited') {
            $teacher->update(['status' => 'active_unverified']);
        }

        $this->notifications->send($user, new PasswordResetByAdmin($newPassword), 'teacher.password_reset_by_admin');

        $this->audit('teacher.password_reset_by_admin', $teacher, [], [], null, $admin->id);
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
        $missingTypes = array_diff(Teacher::REQUIRED_DOCUMENT_TYPES, $uploadedTypes);

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

        $this->assertRequiredDocumentsApproved($teacher);

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
        // إجراء عقابي — يقطع وصول المعلم فوراً بدل الانتظار حتى ينتهي/يُستخدم
        // التوكن الحالي؛ عليه تسجيل الدخول من جديد ليحصل على توكن جديد.
        $teacher->user?->tokens()->delete();
        $this->notifications->send($teacher->user, new TeacherVerificationReviewed(false, $reason), 'teacher.rejected');

        $this->audit('teacher.rejected', $teacher, $old, ['status' => 'rejected'], $reason);

        return $teacher;
    }

    public function suspend(Teacher $teacher, User $admin, string $reason): Teacher
    {
        $this->assertNoLiveOrImminentSessions($teacher);

        $old = $teacher->only(['status']);

        $teacher->update(['status' => 'suspended']);

        // نفس منطق reject(): التعليق إجراء عقابي، يجب أن يقطع الجلسات النشطة
        // فوراً لا أن ينتظر. المعلم لا يزال يستطيع تسجيل الدخول من جديد (تسجيل
        // الدخول غير مشروط بالحالة)، فيحصل على توكن جديد إن احتاج التفاعل مع
        // حسابه أثناء التعليق (مثلاً إعادة رفع وثيقة).
        $teacher->loadMissing('user');
        $teacher->user?->tokens()->delete();

        $this->audit('teacher.suspended', $teacher, $old, ['status' => 'suspended'], $reason);

        return $teacher;
    }

    /**
     * التعليق إجراء يقطع وصول المعلم فوراً — تطبيقه على معلم له جلسة جارية الآن
     * أو جلسة وشيكة (خلال ٢٤ ساعة) يترك طلابها بلا معلم في اللحظة الأخيرة دون
     * بديل. يُمنع التعليق حتى تنتهي تلك الجلسات (بطبيعتها أو بإلغائها/إعادة
     * جدولتها). الجلسات الأبعد لا تمنع — للأدمن وقت للترتيب قبلها.
     *
     * نافذة ٢٤ ساعة متدحرجة لا "نهاية اليوم" التقويمية: scheduled_at مخزّنة UTC
     * و app.timezone=UTC، فـ "اليوم" يختلف حسب منطقة المعلم/الطالب — النافذة
     * المتدحرجة تتفادى غموض حدّ منتصف الليل وتغطّي "جلسات اليوم" في كل المناطق.
     * "قائمة" = حالة scheduled/active (نفس تعريف ScheduleConflictService).
     */
    private function assertNoLiveOrImminentSessions(Teacher $teacher): void
    {
        $blocking = ClassSession::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->where('scheduled_at', '<=', now()->addDay())
            ->get(['id', 'scheduled_at', 'duration_min'])
            ->first(fn (ClassSession $session) => $session->scheduled_at
                ->copy()
                ->addMinutes($session->duration_min)
                ->isAfter(now()));

        if ($blocking) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تعليق الحساب لوجود جلسات نشطة أو جلسات ستبدأ خلال ٢٤ ساعة — يجب انتظار انتهائها أو إلغاؤها أولاً.'],
            ]);
        }
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

        $this->assertRequiredDocumentsApproved($teacher);

        $old = $teacher->only(['status']);

        $teacher->update(['status' => 'verified']);

        $this->audit('teacher.reactivated', $teacher, $old, ['status' => 'verified']);

        return $teacher;
    }

    /**
     * حذف حقيقي (لا تعليق ولا soft delete) — يُسمح به فقط لمعلم status='rejected'
     * تحديداً (RULE، قرار عمل): لم يُوثَّق قط فلا يملك حجوزات/جلسات/مدفوعات/
     * تقييمات حقيقية أصلاً (تلك تتطلب معلماً موثَّقاً ابتداءً)، فحذفه نهائياً
     * آمن. قيود restrictOnDelete على bookings/payments/courses تبقى شبكة أمان
     * أخيرة لو حدث ذلك رغم كل شيء (حالة شاذة غير متوقعة).
     * teachers.user_id → users.id بقيد cascadeOnDelete، فحذف المستخدم وحده
     * يكفي لحذف صف المعلم وكل ما يتفرّع منه (وثائق التوثيق، الشارات، الباقات،
     * جداول subjects/curricula/languages المحورية) تلقائياً على مستوى القاعدة.
     */
    public function deleteTeacher(Teacher $teacher, User $admin): void
    {
        if ($teacher->status !== 'rejected') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف المعلم إلا إذا كانت حالته "مرفوض"'],
            ]);
        }

        $teacher->loadMissing('user', 'verificationDocuments');
        $user = $teacher->user;
        $filePaths = $teacher->verificationDocuments->pluck('s3_path')->all();
        $oldValues = $teacher->only(['status', 'teacher_type']);

        try {
            DB::transaction(function () use ($user) {
                $user?->tokens()->delete();
                $user?->forceDelete();
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف هذا المعلم لوجود بيانات مرتبطة به (حجوزات أو مدفوعات سابقة).'],
            ]);
        }

        // بعد النجاح فقط — لا يصح تسجيل حذف لم يحدث فعلاً لو فشلت المعاملة أعلاه
        $this->audit('teacher.deleted', $teacher, $oldValues, [], null, $admin->id);

        foreach ($filePaths as $path) {
            Storage::disk(VerificationDocument::DISK)->delete($path);
        }
    }

    /**
     * لا يُعتمَد المعلم (توثيقاً أولياً أو إعادة تفعيل بعد تعليق) قبل أن تكون
     * كل الوثائق الثبوتية المطلوبة (REQUIRED_DOCUMENT_TYPES) مُعتمَدة فعلياً —
     * لا يكفي مجرد رفعها. لكل نوع، آخر وثيقة رُفعت منه فقط هي المعتمَدة (قد
     * يرفع المعلم أكثر من مرة لنفس النوع بعد رفض سابق)، لا أي وثيقة تاريخية.
     */
    private function assertRequiredDocumentsApproved(Teacher $teacher): void
    {
        $latestPerType = $teacher->verificationDocuments()
            ->whereIn('type', Teacher::REQUIRED_DOCUMENT_TYPES)
            ->latest('id')
            ->get()
            ->unique('type');

        $approvedTypes = $latestPerType->where('status', 'approved')->pluck('type')->all();
        $missingApproval = array_diff(Teacher::REQUIRED_DOCUMENT_TYPES, $approvedTypes);

        if (! empty($missingApproval)) {
            throw ValidationException::withMessages([
                'documents' => ['لا يمكن اعتماد المعلم قبل الموافقة على جميع الوثائق الثبوتية المطلوبة.'],
            ]);
        }
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
