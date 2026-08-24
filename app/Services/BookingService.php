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
     * بلا وقت. الطالب يختار تاريخاً ووقتاً مستقلَّين لكل جلسة من جلسات الباقة
     * (وليس تاريخاً واحداً يتكرر أسبوعياً على الكل تلقائياً) — $slots مصفوفة
     * بعدد يساوي sessions_count بالضبط، كل عنصر ['date' => 'Y-m-d', 'start_time' => 'H:i'].
     * لا جلسات ولا دفع بعد؛ الحجز يبقى pending_teacher_confirmation حتى يوافق
     * المعلم صراحةً (approveIndividualRequest) أو يرفضه (rejectIndividualRequest).
     */
    public function requestIndividualBooking(Student $student, Package $package, array $slots): Booking
    {
        $this->assertPackageBookable($package);

        if (! $package->isIndividual()) {
            throw ValidationException::withMessages([
                'package' => ['هذه الباقة ليست فردية'],
            ]);
        }

        $this->assertSlotsCountMatchesPackage($package, $slots);

        foreach ($slots as $slot) {
            $this->assertDateMatchesPackageDays($package, Carbon::parse($slot['date']));
        }

        $this->assertNoOpenBookingForPackage($student, $package);

        $timezone = $this->studentTimezone($student);
        $durationMinutes = $this->defaultSessionDurationMinutes();

        // فحص مبكّر استشاري بأفضل تقدير متاح الآن (منطقة الطالب الحالية) —
        // يمنع تقديم طلب تعارض واضح مسبقاً بدل تركه لخيبة أمل عند رفض المعلم
        // له لاحقاً. الفحص الحاسم والملزم فعلياً يحدث عند الموافقة الفعلية
        // (approveIndividualRequest) لأن الجلسات الحقيقية لا تُنشأ إلا حينها.
        $anchors = $this->slotsToAnchors($slots, $timezone);
        $this->assertSlotsNotInPast($anchors);
        $this->assertSlotsDontOverlapEachOther($anchors, $durationMinutes);
        $this->conflicts->assertNoConflict($student, $package->teacher_id, $anchors, $durationMinutes);

        return $this->createBookingRecord($student, $package, [
            'status' => 'pending_teacher_confirmation',
            // العمودان القديمان يبقيان مملوءين بأول جلسة فقط (توافقاً مع أي كود
            // لم يُحدَّث بعد) — requested_slots هو مصدر الحقيقة الكامل والوحيد.
            'requested_date' => $slots[0]['date'],
            'requested_start_time' => $slots[0]['start_time'],
            'requested_slots' => $slots,
            // تُحفَظ الآن كي نستطيع لاحقاً (عند موافقة المعلم) تحويل هذا الوقت الخام
            // بدقة إلى UTC حسب منطقة الطالب الفعلية وقت الطلب، لا افتراض UTC ساذج.
            'requested_timezone' => $timezone,
            'hold_expires_at' => null,
        ]);
    }

    /**
     * المعلم يوافق على طلب حجز فردي — عندها فقط تُنشأ الجلسات (من requested_slots،
     * موعد مستقل لكل جلسة) ويُنشأ الدفع المعلَّق. الطالب يكمل الدفع بعدها عبر
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
            // requested_slots أُدخِلت كوقت حائط محلي لدى الطالب — slotsToAnchors
            // تفسّرها كذلك وتحوّلها فعلياً لتوقيت النظام (UTC) قبل إنشاء الجلسات.
            $anchors = $this->slotsToAnchors($this->requestedSlots($booking), $booking->requested_timezone ?? 'UTC');
            $durationMinutes = $this->defaultSessionDurationMinutes();

            // الفحص الملزم فعلياً — الجلسات الحقيقية تُنشأ خلال ثوانٍ من هنا، فأي
            // تعارض (بما فيه حجز آخر تسارع للموافقة عليه بين طلب هذا الطالب
            // ولحظة موافقة المعلم) يجب أن يمنع إنشاءها نهائياً، لا فقط تحذيراً.
            $this->conflicts->assertNoConflict($booking->student, $booking->package->teacher_id, $anchors, $durationMinutes);

            $sessions = $this->sessionsFromSlots($booking->package, $booking, $anchors);

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

    /** أقرب جلسة مطلوبة زمنياً — إن انقضى وقتها دون موافقة المعلم يصبح الطلب كله باطلاً بصرف النظر عن باقي الجلسات. */
    private function requestedSlotAt(Booking $booking): Carbon
    {
        return $this->slotsToAnchors($this->requestedSlots($booking), $booking->requested_timezone ?? 'UTC')->min();
    }

    /**
     * requested_slots هو مصدر الحقيقة؛ الحجوزات المُنشأة قبل إضافة هذا العمود
     * (أو أي مسار قديم لم يُحدَّث) تُعاد بناؤها من requested_date/requested_start_time
     * كعنصر وحيد، فلا ينكسر شيء بأثر رجعي.
     *
     * @return array<int, array{date: string, start_time: string}>
     */
    private function requestedSlots(Booking $booking): array
    {
        if (! empty($booking->requested_slots)) {
            return $booking->requested_slots;
        }

        return [[
            'date' => $this->dateString($booking->requested_date),
            'start_time' => (string) $booking->requested_start_time,
        ]];
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof Carbon ? $value->toDateString() : (string) $value;
    }

    /**
     * @param  array<int, array{date: string, start_time: string}>  $slots
     * @return Collection<int, Carbon> لحظات البداية بتوقيت النظام (UTC)، مرتَّبة زمنياً
     */
    private function slotsToAnchors(array $slots, string $timezone): Collection
    {
        return collect($slots)
            ->map(fn (array $slot) => Carbon::parse($slot['date'], $timezone)
                ->setTimeFromTimeString($slot['start_time'])
                ->setTimezone(config('app.timezone')))
            ->sort()
            ->values();
    }

    private function assertSlotsCountMatchesPackage(Package $package, array $slots): void
    {
        if (count($slots) !== $package->sessions_count) {
            throw ValidationException::withMessages([
                'slots' => ["يجب اختيار موعد لكل جلسة من جلسات الباقة ({$package->sessions_count} جلسة بالضبط)."],
            ]);
        }
    }

    /**
     * يمنع اختيار موعد قد مضى وقته فعلاً — بعد تحويله للحظة UTC كاملة حسب
     * منطقة الطالب الزمنية الفعلية (slotsToAnchors)، لا بمقارنة نص التاريخ
     * وحده بتاريخ اليوم بتوقيت السيرفر (كانت هذه هي القاعدة القديمة
     * "after_or_equal:today" في الـ FormRequest، وكانت تخطئ لأي طالب في
     * منطقة زمنية غير UTC: "اليوم" عندها قد يكون بالفعل "أمس" أو "غداً"
     * بتوقيت UTC وقت الإرسال، فتُرفض بالخطأ مواعيد اليوم نفسه الصالحة تماماً).
     */
    private function assertSlotsNotInPast(Collection $anchors): void
    {
        if ($anchors->contains(fn (Carbon $anchor) => $anchor->lte(now()))) {
            throw ValidationException::withMessages([
                'slots' => ['لا يمكن اختيار موعد قد مضى وقته.'],
            ]);
        }
    }

    /** يمنع اختيار نفس الموعد (أو موعدين متقاطعين) لأكثر من جلسة ضمن نفس الطلب. */
    private function assertSlotsDontOverlapEachOther(Collection $anchors, int $durationMinutes): void
    {
        $anchors->values()->each(function (Carbon $start, int $i) use ($anchors, $durationMinutes) {
            $end = $start->copy()->addMinutes($durationMinutes);
            $overlapsAnother = $anchors->some(function (Carbon $other, int $j) use ($i, $start, $end, $durationMinutes) {
                if ($j === $i) {
                    return false;
                }
                $otherEnd = $other->copy()->addMinutes($durationMinutes);

                return $other->lt($end) && $start->lt($otherEnd);
            });

            if ($overlapsAnother) {
                throw ValidationException::withMessages([
                    'slots' => ['لا يمكن اختيار نفس الموعد لأكثر من جلسة واحدة.'],
                ]);
            }
        });
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
            // $sessions قد تكون موجودة مسبقاً (انضمام ثانٍ فما فوق لنفس المجموعة) —
            // استبعادها من فحص تعارض المعلم، وإلا تُرفَض بحجة "تعارضها" مع نفسها.
            $this->conflicts->assertNoConflict($student, $package->teacher_id, $sessions->pluck('scheduled_at'), $this->defaultSessionDurationMinutes(), $sessions->pluck('id')->all());

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
     *   يمرر $slots (موعد مستقل لكل جلسة، بعدد يساوي sessions_count) ضمن أيامها
     *   المتاحة، تماماً كطلب الطالب لكن مؤكَّد فوراً.
     * created_by_admin_id و manual_reason إلزاميان (مفروضان أيضاً عبر chk_bookings_manual).
     */
    public function createManualBooking(Student $student, Package $package, User $admin, string $reason, ?array $slots = null): Booking
    {
        $this->assertPackageBookable($package);

        if ($package->isIndividual() && ! $slots) {
            throw ValidationException::withMessages([
                'slots' => ['هذه باقة فردية — يجب تحديد موعد لكل جلسة ضمن أيامها المتاحة'],
            ]);
        }

        if ($package->isIndividual()) {
            $this->assertSlotsCountMatchesPackage($package, $slots);
            foreach ($slots as $slot) {
                $this->assertDateMatchesPackageDays($package, Carbon::parse($slot['date']));
            }
        }

        // نفس القيد المفروض على الحجز الذاتي — الأدمن لا يُستثنى منه، فالمقصود
        // منع الطالب من امتلاك أكثر من اشتراك قائم بنفس الباقة بصرف النظر عمّن ينشئه.
        $this->assertNoOpenBookingForPackage($student, $package);

        return DB::transaction(function () use ($student, $package, $admin, $reason, $slots) {
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
                $anchors = $this->slotsToAnchors($slots, $this->studentTimezone($student));
                $durationMinutes = $this->defaultSessionDurationMinutes();

                $this->assertSlotsNotInPast($anchors);
                $this->assertSlotsDontOverlapEachOther($anchors, $durationMinutes);
                $this->conflicts->assertNoConflict($student, $package->teacher_id, $anchors, $durationMinutes);

                $sessions = $this->sessionsFromSlots($package, $booking, $anchors);
            } else {
                $sessions = $this->sessionsFor($package, $booking);

                $this->conflicts->assertNoConflict($student, $package->teacher_id, $sessions->pluck('scheduled_at'), $this->defaultSessionDurationMinutes(), $sessions->pluck('id')->all());
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
     * زمنياً. الفردية لا تملك جدولاً زمنياً على الإطلاق — انظر sessionsFromSlots.
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
     * فردية فقط — جلسة واحدة لكل لحظة في $anchors (موعد مستقل اختاره الطالب/الأدمن
     * لكل جلسة على حدة)، مرتَّبة زمنياً بحيث sequence_no يطابق ترتيب حدوثها الفعلي.
     */
    private function sessionsFromSlots(Package $package, Booking $ownerBooking, Collection $anchors): Collection
    {
        $durationMinutes = $this->defaultSessionDurationMinutes();

        return $anchors->values()->map(function (Carbon $anchor, int $i) use ($ownerBooking, $package, $durationMinutes) {
            return $ownerBooking->sessions()->create([
                'teacher_id' => $package->teacher_id,
                'sequence_no' => $i + 1,
                'scheduled_at' => $anchor,
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
