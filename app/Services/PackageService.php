<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\CampaignPackageAssigned;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PackageService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * باقة حملة مخفضة ينشئها الأدمن باسم معلم — فردية دائماً، نشطة فوراً بلا دورة مراجعة
     * وهامش: السعر الذي يحدده الأدمن هو سعر الطالب، ومبلغه كله للمنصة (teacher_price = 0،
     * platform_revenue = السعر) لأن المعلم يأخذ عنها راتباً شهرياً ثابتاً. طلبات حجزها تمر
     * بموافقة المعلم على الموعد كأي باقة فردية، وتظهر لحسابات الحملة فقط.
     */
    public function createCampaignPackage(Teacher $teacher, User $admin, array $data): Package
    {
        if ($teacher->isTrainingCenter() || ! $teacher->isVerified()) {
            throw ValidationException::withMessages([
                'teacher_id' => ['يجب أن يكون المعلم موثّقاً وليس مركزاً تدريبياً'],
            ]);
        }

        return DB::transaction(function () use ($teacher, $admin, $data) {
            $price = round((float) $data['price'], 2);

            $package = Package::create([
                'teacher_id' => $teacher->id,
                'schedule_timezone' => $teacher->loadMissing('user')->user->timezone ?? 'UTC',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'session_format' => 'individual',
                'is_trial' => false,
                'audience' => Package::AUDIENCE_CAMPAIGN,
                'capacity' => 1,
                'sessions_count' => $data['sessions_count'],
                'teacher_price' => 0,
                'platform_margin_percent' => 100,
                'student_price' => $price,
                'platform_revenue' => $price,
                'grades' => ! empty($data['grades']) ? array_values($data['grades']) : null,
                'status' => 'active',
                'submitted_at' => now(),
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ]);

            if (! empty($data['curriculum_ids'])) {
                $package->curricula()->sync($data['curriculum_ids']);
            }

            if (! empty($data['stage_ids'])) {
                $package->stages()->sync($data['stage_ids']);
            }

            $this->syncSchedules($package, $data['schedules'], $teacher);

            $this->audit('package.campaign_created', $package, [], [
                'teacher_id' => $teacher->id,
                'student_price' => $price,
                'sessions_count' => $package->sessions_count,
            ], null, $admin->id);

            DB::afterCommit(fn () => $this->notifications->send(
                $teacher->user,
                new CampaignPackageAssigned($package),
                'package.campaign_assigned',
            ));

            return $package->fresh(['curricula', 'stages', 'schedules', 'teacher.user:id,name', 'subject:id,name_ar']);
        });
    }

    public function createDraft(Teacher $teacher, array $data): Package
    {
        if ($teacher->isTrainingCenter()) {
            throw ValidationException::withMessages([
                'teacher_type' => ['المراكز التدريبية لا يمكنها إنشاء باقات — الدورات التدريبية فقط'],
            ]);
        }

        if (! $teacher->isVerified()) {
            throw ValidationException::withMessages([
                'teacher_status' => ['يجب توثيق الحساب أولاً قبل إنشاء باقة'],
            ]);
        }

        $data = $this->applyTrialPackageValues($data, false);

        $curriculumIds = $data['curriculum_ids'] ?? [];
        $stageIds = $data['stage_ids'] ?? [];
        $schedules = $data['schedules'] ?? [];
        unset($data['curriculum_ids'], $data['stage_ids'], $data['schedules']);

        // أرقام صفوف صِرفة (1-12) بلا جدول تصنيف — عمود JSON مباشر على الباقة
        // لا علاقة pivot تحتاج sync()، بعكس stage_ids/curriculum_ids.
        $data['grades'] = ! empty($data['grades']) ? array_values($data['grades']) : null;

        $package = Package::create(array_merge($data, [
            'teacher_id' => $teacher->id,
            'status' => 'draft',
            // منطقة المعلم وقت إنشاء الجدول — تُستخدَم لاحقاً لتحويل أوقات package_schedules
            // الخام (start_time) إلى UTC بدقة بدل افتراضها UTC ساذجاً (BookingService::sessionsFor)
            'schedule_timezone' => $teacher->loadMissing('user')->user->timezone ?? 'UTC',
        ]));

        if ($curriculumIds) {
            $package->curricula()->sync($curriculumIds);
        }

        if ($stageIds) {
            $package->stages()->sync($stageIds);
        }

        if ($schedules) {
            $this->syncSchedules($package, $schedules, $teacher);
        }

        return $package->fresh(['curricula', 'stages', 'schedules']);
    }

    public function updateDraft(Package $package, array $data): Package
    {
        if ($package->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعديل الباقة إلا في حالة المسودة — عطّل الباقة الحالية وأنشئ باقة جديدة بدلاً من ذلك'],
            ]);
        }

        $data = $this->applyTrialPackageValues($data, $package->is_trial);

        $curriculumIds = $data['curriculum_ids'] ?? null;
        $stageIds = $data['stage_ids'] ?? null;
        $schedules = $data['schedules'] ?? null;
        unset($data['curriculum_ids'], $data['stage_ids'], $data['schedules']);

        if (array_key_exists('grades', $data) && empty($data['grades'])) {
            $data['grades'] = null;
        }

        $package->fill($data);
        $package->save();

        if ($curriculumIds !== null) {
            $package->curricula()->sync($curriculumIds);
        }

        if ($stageIds !== null) {
            $package->stages()->sync($stageIds);
        }

        if ($schedules !== null) {
            $this->syncSchedules($package, $schedules, $package->teacher);
        }

        return $package->fresh(['curricula', 'stages', 'schedules']);
    }

    /**
     * باقة التجربة المجانية: جلسة واحدة فردية بسعر صفر — تُفرض هنا لا في الطلب وحده، ومعها
     * تُمسح المادة والمناهج والمراحل والصفوف والخصم (تحويل مسودة عادية إلى مجانية لا يُبقي
     * بيانات مضللة منها). الموافقة تمر كالمعتاد: teacher_price=0 يعطي student_price=0 أياً كانت
     * نسبة المنصة.
     */
    private function applyTrialPackageValues(array $data, bool $currentlyTrial): array
    {
        if (! ($data['is_trial'] ?? $currentlyTrial)) {
            return $data;
        }

        return array_merge($data, [
            'is_trial' => true,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 0,
            'subject_id' => null,
            'discount_percent' => null,
            'grades' => null,
            'curriculum_ids' => [],
            'stage_ids' => [],
        ]);
    }

    public function submitForApproval(Package $package): Package
    {
        if ($package->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن إرسال الباقة للمراجعة من هذه الحالة'],
            ]);
        }

        $package->update(['status' => 'pending_approval', 'submitted_at' => now()]);

        $this->audit('package.submitted', $package);

        return $package;
    }

    public function disable(Package $package): Package
    {
        if (! in_array($package->status, ['active', 'full'], true)) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعطيل الباقة من هذه الحالة'],
            ]);
        }

        if ($package->hasLiveOrImminentSessions()) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تعطيل الباقة لوجود جلسات نشطة أو ستبدأ خلال ٢٤ ساعة — يجب انتظار انتهائها أو إلغاؤها أولاً.'],
            ]);
        }

        $package->update(['status' => 'disabled']);

        $this->audit('package.disabled', $package);

        return $package;
    }

    /**
     * عكس disable() — لا حاجة لفحص جلسات هنا: الباقة المعطّلة أصلاً غير قابلة
     * للحجز (isBookable/scopeBookable يشترطان status='active')، فلا جلسات
     * جديدة قد تنشأ عليها وهي معطّلة لتُفحَص.
     */
    public function enable(Package $package): Package
    {
        if ($package->status !== 'disabled') {
            throw ValidationException::withMessages([
                'status' => ['يمكن إعادة التفعيل فقط لباقة معطّلة'],
            ]);
        }

        $package->update(['status' => 'active']);

        $this->audit('package.enabled', $package);

        return $package;
    }

    /**
     * الجدول الثابت — يُستبدل بالكامل مع كل تحديث، وليس تعديلاً تراكمياً.
     *
     * جماعية: كل صف هو تاريخ جلسة فعلي يختاره المعلم صراحةً من روزنامة — لا تكرار
     *   أسبوعي تلقائي إطلاقاً، فيجب أن يساوي عدد الصفوف sessions_count بالضبط.
     *   day_of_week يُشتق من كل تاريخ، وend_time من إعداد session_duration_minutes
     *   (ثابتة، غير قابلة لتحكم المعلم).
     * فردية: المعلم يحدد فقط الأيام المتاحة لهذه الباقة — يجب أن تكون كل يوم
     *   ضمن أيام توفّره العامة (availability_slots)، وإلا رُفض الطلب. لا تاريخ
     *   ولا وقت على مستوى الباقة إطلاقاً — الطالب يحددهما لاحقاً عبر طلب حجز.
     */
    private function syncSchedules(Package $package, array $schedules, Teacher $teacher): void
    {
        $package->schedules()->delete();

        if ($package->session_format === 'individual') {
            $availableDays = $teacher->availabilitySlots()->pluck('day_of_week')->all();

            foreach ($schedules as $schedule) {
                $day = (int) $schedule['day_of_week'];

                if (! in_array($day, $availableDays, true)) {
                    throw ValidationException::withMessages([
                        'schedules' => ['اليوم المحدد ليس ضمن أيام توفّرك — أضِفه أولاً من إعدادات التوفر'],
                    ]);
                }

                $package->schedules()->create(['day_of_week' => $day]);
            }

            return;
        }

        if (count($schedules) !== $package->sessions_count) {
            throw ValidationException::withMessages([
                'schedules' => ["يجب تحديد تاريخ لكل جلسة من جلسات الباقة ({$package->sessions_count}) — لا تكرار تلقائي"],
            ]);
        }

        $durationMinutes = (int) $this->settings->get('session_duration_minutes', 60);

        foreach ($schedules as $schedule) {
            $date = Carbon::parse($schedule['date']);
            $endTime = Carbon::parse($schedule['start_time'])->addMinutes($durationMinutes)->format('H:i');

            $package->schedules()->create([
                'date' => $date->toDateString(),
                'day_of_week' => $date->dayOfWeek,
                'start_time' => $schedule['start_time'],
                'end_time' => $endTime,
            ]);
        }
    }
}
