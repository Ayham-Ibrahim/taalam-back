<?php

namespace App\Services;

use App\Jobs\CreateBbbRoomJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Payment;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    use LogsAuditEvents;

    private const EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON = 'انتهى وقت الجلسة المقترحة دون موافقة المعلم.';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ScheduleConflictService $conflicts,
    ) {}

    /**
     * فردي (خاص بمعلم مدرسي/جامعي): المعلم يحدد فقط الأيام المتاحة لهذه الباقة —
     * بلا وقت. الطالب هنا يختار تاريخاً (ضمن تلك الأيام) ووقتاً حراً ويقدّم طلب
     * حجز — لا جلسات ولا دفع بعد. الحجز يبقى pending_teacher_confirmation حتى
     * يوافق المعلم صراحةً (approveIndividualRequest) أو يرفضه (rejectIndividualRequest).
     */
    public function requestIndividualBooking(Student $student, Package $package, string $date, string $startTime): Booking
    {
        $this->assertPackageBookable($package);

        if (! $package->isIndividual()) {
            throw ValidationException::withMessages([
                'package' => ['هذه الباقة ليست فردية'],
            ]);
        }

        $requestedDate = Carbon::parse($date);
        $this->assertDateMatchesPackageDays($package, $requestedDate);

        $this->assertNoOpenBookingForPackage($student, $package);

        // فحص مبكّر استشاري بأفضل تقدير متاح الآن (منطقة الطالب الحالية) —
        // يمنع تقديم طلب تعارض واضح مسبقاً بدل تركه لخيبة أمل عند رفض المعلم
        // له لاحقاً. الفحص الحاسم والملزم فعلياً يحدث عند الموافقة الفعلية
        // (approveIndividualRequest) لأن الجلسات الحقيقية لا تُنشأ إلا حينها.
        $anchorGuess = Carbon::parse($requestedDate->toDateString(), $this->studentTimezone($student))
            ->setTimeFromTimeString($startTime)
            ->setTimezone(config('app.timezone'));
        $this->conflicts->assertNoConflict(
            $student,
            $this->weeklyOccurrences($anchorGuess, $package->sessions_count),
            $this->defaultSessionDurationMinutes(),
        );

        return $this->createBookingRecord($student, $package, [
            'status' => 'pending_teacher_confirmation',
            'requested_date' => $requestedDate->toDateString(),
            'requested_start_time' => $startTime,
            // تُحفَظ الآن كي نستطيع لاحقاً (عند موافقة المعلم) تحويل هذا الوقت الخام
            // بدقة إلى UTC حسب منطقة الطالب الفعلية وقت الطلب، لا افتراض UTC ساذج.
            'requested_timezone' => $this->studentTimezone($student),
            'hold_expires_at' => null,
        ]);
    }

    /**
     * المعلم يوافق على طلب حجز فردي — عندها فقط تُنشأ الجلسات (من requested_date/
     * requested_start_time) ويُنشأ الدفع المعلَّق. الطالب يكمل الدفع بعدها عبر
     * BookingController::checkout.
     */
    public function approveIndividualRequest(Booking $booking, User $teacher): Booking
    {
        $booking->loadMissing(['package', 'student']);

        if ($this->expirePendingTeacherConfirmationIfNeeded($booking)) {
            throw ValidationException::withMessages([
                'status' => [self::EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON],
            ]);
        }

        if ($booking->status !== 'pending_teacher_confirmation') {
            throw ValidationException::withMessages([
                'status' => ['هذا الطلب لم يعد بانتظار الموافقة'],
            ]);
        }

        $this->assertPackageBookable($booking->package);

        return DB::transaction(function () use ($booking, $teacher) {
            // requested_date/requested_start_time أُدخِلا كوقت حائط محلي لدى الطالب —
            // parse($date, $tz) يفسّرهما كذلك، وsetTimezone() يحوّلهما فعلياً لتوقيت
            // النظام (UTC) قبل التخزين، لا مجرد وسم رقمين خام بمنطقة مختلفة.
            // ملاحظة: requested_date مُحوَّل مسبقاً لكائن Carbon (date cast) — يجب تمرير
            // نص خام (toDateString) لا الكائن نفسه، وإلا Carbon::parse يتجاهل $tz تماماً
            // لأن الكائن يحمل منطقته الزمنية الخاصة أصلاً (UTC من الـ cast).
            $anchor = Carbon::parse($this->dateString($booking->requested_date), $booking->requested_timezone ?? 'UTC')
                ->setTimeFromTimeString((string) $booking->requested_start_time)
                ->setTimezone(config('app.timezone'));

            // الفحص الملزم فعلياً — الجلسات الحقيقية تُنشأ خلال ثوانٍ من هنا، فأي
            // تعارض (بما فيه حجز آخر تسارع للموافقة عليه بين طلب هذا الطالب
            // ولحظة موافقة المعلم) يجب أن يمنع إنشاءها نهائياً، لا فقط تحذيراً.
            $this->conflicts->assertNoConflict(
                $booking->student,
                $this->weeklyOccurrences($anchor, $booking->package->sessions_count),
                $this->defaultSessionDurationMinutes(),
            );

            $sessions = $this->sessionsFromAnchor($booking->package, $booking, $anchor);

            foreach ($sessions as $session) {
                $this->registerAttendee($session, $booking->student, booking: $booking);
            }

            $booking->update([
                'status' => 'pending_payment',
                'hold_expires_at' => now()->addMinutes((int) $this->settings->get('booking_payment_hold_minutes', 15)),
            ]);

            $this->createPendingPayment($booking);

            $this->audit('booking.request_approved', $booking, [], [], null, $teacher->id);

            return $booking->fresh(['sessions']);
        });
    }

    /** المعلم يرفض طلب الحجز — لا جلسات أُنشئت ولا دفع تم، فلا شيء لاسترداده. */
    public function rejectIndividualRequest(Booking $booking, User $teacher, ?string $reason): Booking
    {
        if ($this->expirePendingTeacherConfirmationIfNeeded($booking)) {
            throw ValidationException::withMessages([
                'status' => [self::EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON],
            ]);
        }

        if ($booking->status !== 'pending_teacher_confirmation') {
            throw ValidationException::withMessages([
                'status' => ['هذا الطلب لم يعد بانتظار الموافقة'],
            ]);
        }

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->audit('booking.request_rejected', $booking, [], [], $reason, $teacher->id);

        return $booking;
    }

    public function expireStalePendingTeacherConfirmations(): int
    {
        $updated = 0;

        Booking::query()
            ->where('status', 'pending_teacher_confirmation')
            ->whereNotNull('requested_date')
            ->whereNotNull('requested_start_time')
            ->lazyById()
            ->each(function (Booking $booking) use (&$updated): void {
                if ($this->expirePendingTeacherConfirmationIfNeeded($booking)) {
                    $updated++;
                }
            });

        return $updated;
    }

    public function pendingTeacherConfirmationExpiredMessage(): string
    {
        return self::EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON;
    }

    private function expirePendingTeacherConfirmationIfNeeded(Booking $booking): bool
    {
        if ($booking->status !== 'pending_teacher_confirmation') {
            return false;
        }

        if (! $this->requestedSlotAt($booking)->lte(now())) {
            return false;
        }

        $booking->update([
            'status' => 'expired',
            'cancelled_at' => now(),
            'cancellation_reason' => self::EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON,
        ]);

        $this->audit('booking.request_expired', $booking, ['status' => 'pending_teacher_confirmation'], ['status' => 'expired'], self::EXPIRED_PENDING_TEACHER_CONFIRMATION_REASON);

        return true;
    }

    private function requestedSlotAt(Booking $booking): Carbon
    {
        return Carbon::parse($this->dateString($booking->requested_date), $booking->requested_timezone ?? 'UTC')
            ->setTimeFromTimeString((string) $booking->requested_start_time)
            ->setTimezone(config('app.timezone'));
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof Carbon ? $value->toDateString() : (string) $value;
    }

    private function assertDateMatchesPackageDays(Package $package, Carbon $date): void
    {
        $availableDays = $package->schedules()->pluck('day_of_week')->all();

        if (! in_array($date->dayOfWeek, $availableDays, true)) {
            throw ValidationException::withMessages([
                'requested_date' => ['هذا اليوم ليس ضمن الأيام المتاحة لهذه الباقة'],
            ]);
        }
    }

    /**
     * مجموعة: الطالب ينضم لجدول ثابت مشترك. أول حجز يُنشئ جلسات المجموعة
     * (بعدد sessions_count بدءاً من تاريخ package_schedules الذي حدده المعلم)،
     * وكل انضمام لاحق يُسجَّل كحاضر على نفس الجلسات الموجودة.
     */
    public function joinGroupPackage(Student $student, Package $package): Booking
    {
        $this->assertPackageBookable($package);

        if ($package->isIndividual()) {
            throw ValidationException::withMessages([
                'package' => ['هذه الباقة فردية وليست جماعية'],
            ]);
        }

        if ($package->enrolled_count >= $package->capacity) {
            throw ValidationException::withMessages([
                'package' => ['اكتمل نصاب هذه المجموعة'],
            ]);
        }

        // لم يكن هناك أي منع سابقاً لنفس الطالب من إنشاء أكثر من سطر حجز لنفس
        // الباقة الجماعية (كل ضغطة "انضمام" تُنشئ Booking جديداً) — هذا لا يمنع
        // طلاباً آخرين من الانضمام، فالفحص مقيَّد بـ student_id+package_id معاً.
        $this->assertNoOpenBookingForPackage($student, $package);

        return DB::transaction(function () use ($student, $package) {
            $booking = $this->createBookingRecord($student, $package);

            // sessionsFor() قد تكون هذه أول عملية انضمام (تُنشئ جلسات المجموعة
            // فعلياً) أو انضماماً لاحقاً (تُعيد الموجودة) — إما حال، الجلسات نفسها
            // مملوكة للباقة لا لهذا الطالب تحديداً، فإنشاؤها هنا غير خطير. الخطر
            // الفعلي هو تسجيل الطالب حاضراً (registerAttendee) في وقت متعارض —
            // لذا الفحص يسبق ذلك مباشرة، وأي تعارض يُلغي كامل المعاملة (rollback
            // تلقائي عبر DB::transaction) فلا يبقى حجز ولا سطر حضور جديد.
            $sessions = $this->sessionsFor($package, $booking);
            $this->conflicts->assertNoConflict($student, $sessions->pluck('scheduled_at'), $this->defaultSessionDurationMinutes());

            foreach ($sessions as $session) {
                $this->registerAttendee($session, $student, booking: $booking);
            }

            $this->createPendingPayment($booking);

            return $booking->fresh(['sessions']);
        });
    }

    /**
     * حجز يدوي من الأدمن نيابةً عن طالب — بدون Stripe، ومؤكّد فوراً.
     * جماعية: تستخدم جدول الباقة الثابت مباشرة (لا موعد مخصّص يحدده الأدمن).
     * فردية: لا جدول زمني على مستوى الباقة إطلاقاً (أيام فقط) — الأدمن يجب أن
     *   يمرر تاريخاً ووقتاً ضمن أيامها المتاحة، تماماً كطلب الطالب لكن مؤكَّد فوراً.
     * created_by_admin_id و manual_reason إلزاميان (مفروضان أيضاً عبر chk_bookings_manual).
     */
    public function createManualBooking(Student $student, Package $package, User $admin, string $reason, ?string $date = null, ?string $startTime = null): Booking
    {
        $this->assertPackageBookable($package);

        if ($package->isIndividual() && (! $date || ! $startTime)) {
            throw ValidationException::withMessages([
                'date' => ['هذه باقة فردية — يجب تحديد تاريخ ووقت ضمن أيامها المتاحة'],
            ]);
        }

        // نفس القيد المفروض على الحجز الذاتي — الأدمن لا يُستثنى منه، فالمقصود
        // منع الطالب من امتلاك أكثر من اشتراك قائم بنفس الباقة بصرف النظر عمّن ينشئه.
        $this->assertNoOpenBookingForPackage($student, $package);

        return DB::transaction(function () use ($student, $package, $admin, $reason, $date, $startTime) {
            $booking = $this->createBookingRecord($student, $package, [
                'is_manual' => true,
                'created_by_admin_id' => $admin->id,
                'manual_reason' => $reason,
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'hold_expires_at' => null,
            ]);

            // الحجز اليدوي مؤكَّد فوراً بلا مرحلة موافقة وسيطة، فهو الفرصة
            // الوحيدة لمنع التعارض هنا — لا فرق عن الحجز الذاتي من ناحية
            // الالتزام الفعلي بالجلسة.
            if ($package->isIndividual()) {
                // نفس منطقة الطالب المستهدَف — الأدمن يحجز نيابةً عنه فالوقت المقصود هو وقته المحلي هو، لا وقت الأدمن
                $requestedDate = Carbon::parse($date, $this->studentTimezone($student));
                $this->assertDateMatchesPackageDays($package, $requestedDate);
                $anchor = $requestedDate->setTimeFromTimeString($startTime)->setTimezone(config('app.timezone'));

                $this->conflicts->assertNoConflict(
                    $student,
                    $this->weeklyOccurrences($anchor, $package->sessions_count),
                    $this->defaultSessionDurationMinutes(),
                );

                $sessions = $this->sessionsFromAnchor($package, $booking, $anchor);
            } else {
                $sessions = $this->sessionsFor($package, $booking);

                $this->conflicts->assertNoConflict($student, $sessions->pluck('scheduled_at'), $this->defaultSessionDurationMinutes());
            }

            foreach ($sessions as $session) {
                $this->registerAttendee($session, $student, booking: $booking);
            }

            $this->createManualPayment($booking);
            $this->incrementEnrolledCount($package);

            $this->audit('booking.manual_created', $booking, [], [
                'student_id' => $student->id,
                'package_id' => $package->id,
            ], $reason, $admin->id);

            return $booking->fresh(['sessions']);
        });
    }

    /**
     * يُستدعى بعد نجاح الدفع (من PaymentService عبر webhook Stripe). عملية idempotent —
     * استدعاؤها لحجز مؤكَّد مسبقاً لا يُحدث شيئاً.
     */
    public function confirmBooking(Booking $booking): Booking
    {
        if ($booking->status !== 'pending_payment') {
            return $booking;
        }

        $booking->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'hold_expires_at' => null,
        ]);

        $booking->loadMissing(['package', 'sessions']);
        $this->incrementEnrolledCount($booking->package);

        foreach ($booking->sessions as $session) {
            CreateBbbRoomJob::dispatch($session->id);
        }

        return $booking;
    }

    private function assertPackageBookable(Package $package): void
    {
        if ($package->status !== 'active') {
            throw ValidationException::withMessages([
                'package' => ['هذه الباقة غير متاحة للحجز حالياً'],
            ]);
        }
    }

    /**
     * "قائم لم ينتهِ" = طلب/حجز بحالة pending_teacher_confirmation أو
     * pending_payment أو confirmed أو active — أي شيء لم يُلغَ/يُرفض/ينتهِ/يكتمل
     * بعد. مُطبَّق على كل مسارات إنشاء الحجز (ذاتي فردي/جماعي ويدوي من الأدمن)
     * بلا استثناء، فالقيد على الطالب لا على من يُنشئ الحجز نيابةً عنه.
     */
    private function assertNoOpenBookingForPackage(Student $student, Package $package): void
    {
        $hasOpenBooking = Booking::where('student_id', $student->id)
            ->where('package_id', $package->id)
            ->whereIn('status', ['pending_teacher_confirmation', 'pending_payment', 'confirmed', 'active'])
            ->exists();

        if ($hasOpenBooking) {
            throw ValidationException::withMessages([
                'package' => ['لا يمكن الاشتراك في هذه الباقة لأن لديك اشتراكًا قائمًا لم ينتهِ بعد.'],
            ]);
        }
    }

    private function studentTimezone(Student $student): string
    {
        return $student->loadMissing('user')->user->timezone ?? 'UTC';
    }

    /**
     * @return Collection<int, Carbon> $count لحظة بدء، أسبوعياً بدءاً من $anchor —
     *                                  نفس تكرار sessionsFromAnchor بالضبط.
     */
    private function weeklyOccurrences(Carbon $anchor, int $count): Collection
    {
        return collect(range(0, $count - 1))->map(fn (int $i) => $anchor->copy()->addWeeks($i));
    }

    /**
     * عمداً update() وليس increment() — increment() ينفّذ UPDATE مباشراً في قاعدة
     * البيانات متجاوزاً حدث saving، فلا يعمل PackageObserver (الإغلاق التلقائي عند full).
     */
    private function incrementEnrolledCount(Package $package): void
    {
        $package->update(['enrolled_count' => $package->enrolled_count + 1]);
    }

    private function createBookingRecord(Student $student, Package $package, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'reference' => 'BK-'.strtoupper(Str::random(10)),
            'student_id' => $student->id,
            'teacher_id' => $package->teacher_id,
            'package_id' => $package->id,
            'amount_paid' => $package->student_price,
            // teacher_price سعر الساعة الواحدة — مستحق المعلم الكلي = ×sessions_count (PricingService::calculateStudentPrice نفس الصيغة)
            'teacher_amount' => round($package->teacher_price * $package->sessions_count, 2),
            'platform_amount' => $package->platform_revenue,
            'margin_percent_snapshot' => $package->platform_margin_percent,
            'currency' => $package->currency,
            'sessions_total' => $package->sessions_count,
            'sessions_used' => 0,
            'sessions_remaining' => $package->sessions_count,
            'expires_at' => $package->validity_days ? now()->addDays($package->validity_days)->toDateString() : null,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->addMinutes((int) $this->settings->get('booking_payment_hold_minutes', 15)),
        ], $overrides));
    }

    private function registerAttendee(ClassSession $session, Student $student, Booking $booking): SessionAttendee
    {
        return SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }

    private function createPendingPayment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'amount' => $booking->amount_paid,
            'provider_amount' => $booking->teacher_amount,
            'platform_amount' => $booking->platform_amount,
            'currency' => $booking->currency,
            'method' => 'stripe',
            'status' => 'pending',
        ]);
    }

    private function createManualPayment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'amount' => $booking->amount_paid,
            'provider_amount' => $booking->teacher_amount,
            'platform_amount' => $booking->platform_amount,
            'currency' => $booking->currency,
            'method' => 'manual',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * جماعية فقط الآن (والحجز اليدوي لباقة جماعية) — تُرجع جلسات الباقة الموجودة
     * أو تُنشئها لأول مرة. كل صف في package_schedules هو تاريخ جلسة فعلي اختاره
     * المعلم صراحةً من روزنامة (لا تكرار أسبوعي تلقائي) — PackageService يضمن
     * أن عدد الصفوف يساوي sessions_count بالضبط، فكل صف يصبح جلسة واحدة مرتّبة
     * زمنياً. الفردية لا تملك جدولاً زمنياً على الإطلاق — انظر sessionsFromAnchor.
     */
    private function sessionsFor(Package $package, Booking $ownerBooking): Collection
    {
        $existing = ClassSession::whereHas('booking', fn ($q) => $q->where('package_id', $package->id))
            ->orderBy('sequence_no')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $schedules = $package->schedules()->orderBy('date')->orderBy('start_time')->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'package' => ['لا يوجد جدول محدد لهذه الباقة بعد — تواصل مع المعلم'],
            ]);
        }

        $durationMinutes = $this->defaultSessionDurationMinutes();

        return $schedules->values()->map(function ($schedule, int $index) use ($ownerBooking, $package, $durationMinutes) {
            // start_time أُدخِل بمنطقة المعلم وقت إنشاء الباقة (Package::schedule_timezone) — لا UTC
            // ساذجة. schedule->date كائن Carbon مُحوَّل مسبقاً (date cast) لا نص خام — toDateString()
            // ضروري هنا، وإلا Carbon::parse يتجاهل $tz تماماً (نفس ملاحظة approveIndividualRequest).
            $scheduledAt = Carbon::parse($this->dateString($schedule->date), $package->schedule_timezone ?? 'UTC')
                ->setTimeFromTimeString((string) $schedule->start_time)
                ->setTimezone(config('app.timezone'));

            return $ownerBooking->sessions()->create([
                'teacher_id' => $package->teacher_id,
                'sequence_no' => $index + 1,
                'scheduled_at' => $scheduledAt,
                'duration_min' => $durationMinutes,
                'status' => 'scheduled',
            ]);
        });
    }

    /**
     * فردية فقط — الجلسات الـ sessions_count تُنشأ أسبوعياً بدءاً من نقطة زمنية
     * واحدة (التاريخ/الوقت الذي وافق عليه المعلم من طلب الطالب، أو حدده الأدمن
     * في الحجز اليدوي). لا يوجد صفوف جدول متعددة هنا كما في الجماعية.
     */
    private function sessionsFromAnchor(Package $package, Booking $ownerBooking, Carbon $anchor): Collection
    {
        $durationMinutes = $this->defaultSessionDurationMinutes();

        return collect(range(0, $package->sessions_count - 1))->map(function (int $i) use ($ownerBooking, $package, $anchor, $durationMinutes) {
            return $ownerBooking->sessions()->create([
                'teacher_id' => $package->teacher_id,
                'sequence_no' => $i + 1,
                'scheduled_at' => $anchor->copy()->addWeeks($i),
                'duration_min' => $durationMinutes,
                'status' => 'scheduled',
            ]);
        });
    }

    /**
     * كل الجلسات مدتها ثابتة (session_duration_minutes) — سياسة منصة، لا يتحكم
     * بها المعلم إطلاقاً ولا تُشتق من end_time الجدول (end_time نفسه محسوب من
     * هذا الإعداد عند حفظ الجدول في PackageService::syncSchedules).
     */
    private function defaultSessionDurationMinutes(): int
    {
        return (int) $this->settings->get('session_duration_minutes', 60);
    }
}
