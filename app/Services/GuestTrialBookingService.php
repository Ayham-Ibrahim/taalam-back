<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GuestEmailVerification;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;
use App\Notifications\GuestTrialBookingStatus;
use App\Notifications\GuestVerificationCode;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * حجز الزائر للباقة المجانية بلا حساب: يثبت ملكية بريده برمز (OTP)، ثم يُنشأ له حساب ظل
 * (account_type = guest، بلا كلمة مرور ولا دخول) يحمل الحجز — فيعمل كل منطق الحجز والجلسات
 * والحضور وتذكير الجلسة برابط الانضمام الموقّع كما هو. قيد "مرة لكل بريد مع كل معلم" يُفرض في
 * BookingService نفسه، مشتركاً مع الطلاب المسجّلين.
 */
class GuestTrialBookingService
{
    private const CODE_VALID_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly NotificationService $notifications,
    ) {}

    public function sendCode(Package $package, string $email, ?string $ip): void
    {
        $this->assertGuestTrialPackage($package);

        $normalized = EmailNormalizer::normalize($email);

        $this->assertEmailCanBookAsGuest($normalized, $package);

        $recent = GuestEmailVerification::where('normalized_email', $normalized)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages([
                'email' => ['أُرسل رمز قبل قليل — انتظر دقيقة قبل طلب رمز جديد'],
            ]);
        }

        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($email, $normalized, $code, $ip) {
            // رمز جديد يُبطل كل ما سبقه لنفس البريد
            GuestEmailVerification::where('normalized_email', $normalized)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            GuestEmailVerification::create([
                'email' => $email,
                'normalized_email' => $normalized,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::CODE_VALID_MINUTES),
                'ip_address' => $ip,
            ]);
        });

        Notification::route('mail', $email)->notify(new GuestVerificationCode($code, self::CODE_VALID_MINUTES));
    }

    /**
     * @param  array{email: string, code: string, name: string, timezone: string, slots: array}  $data
     */
    public function book(Package $package, array $data): Booking
    {
        $this->assertGuestTrialPackage($package);

        $normalized = EmailNormalizer::normalize($data['email']);

        $verification = $this->verifyCode($normalized, $data['code']);

        $booking = DB::transaction(function () use ($package, $data, $normalized, $verification) {
            $verification = GuestEmailVerification::whereKey($verification->id)->lockForUpdate()->first();

            if ($verification->used_at !== null) {
                throw ValidationException::withMessages([
                    'code' => ['رمز التحقق مستخدم مسبقاً — اطلب رمزاً جديداً'],
                ]);
            }

            $this->assertEmailCanBookAsGuest($normalized, $package);

            $student = $this->guestStudentFor($data, $normalized);

            // نفس مسار الطالب المسجّل حرفياً — فحوص الموعد والتعارض وقيد المرة الواحدة مع المعلم
            $booking = $this->bookings->requestIndividualBooking($student, $package, $data['slots']);

            $verification->update(['used_at' => now()]);

            return $booking;
        });

        $user = $booking->loadMissing('student.user')->student->user;

        $this->notifications->send($user, new GuestTrialBookingStatus($booking, GuestTrialBookingStatus::RECEIVED), 'guest_trial.received');

        return $booking;
    }

    private function verifyCode(string $normalized, string $code): GuestEmailVerification
    {
        $verification = GuestEmailVerification::where('normalized_email', $normalized)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $verification || ! $verification->isUsable()) {
            throw ValidationException::withMessages([
                'code' => ['رمز التحقق منتهٍ أو غير موجود — اطلب رمزاً جديداً'],
            ]);
        }

        if (! Hash::check($code, $verification->code_hash)) {
            // خارج أي معاملة عمداً — المحاولة الخاطئة تُحسب ولو فشل كل ما بعدها
            $verification->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['رمز التحقق غير صحيح'],
            ]);
        }

        return $verification;
    }

    /** الحساب الذي يحمل حجز الزائر: حساب ظله السابق بنفس البريد إن وُجد، وإلا حساب ظل جديد */
    private function guestStudentFor(array $data, string $normalized): Student
    {
        $user = User::where('normalized_email', $normalized)
            ->where('account_type', User::ACCOUNT_GUEST)
            ->lockForUpdate()
            ->first();

        if ($user) {
            $user->update(['name' => $data['name'], 'timezone' => $data['timezone']]);

            return $user->student()->first() ?? Student::create(['user_id' => $user->id]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'student',
            'account_type' => User::ACCOUNT_GUEST,
            'password' => null,
            'timezone' => $data['timezone'],
        ]);

        return Student::create(['user_id' => $user->id]);
    }

    /** باقة تجربة مجانية عامة قابلة للحجز لمعلم موثّق — لا غير */
    private function assertGuestTrialPackage(Package $package): void
    {
        if (! $package->is_trial || $package->isCampaign() || ! $package->isBookable()
            || ! $package->loadMissing('teacher')->teacher?->isVerified()) {
            throw ValidationException::withMessages([
                'package' => ['هذه الباقة غير متاحة للحجز بلا حساب'],
            ]);
        }
    }

    /**
     * البريد لحساب مسجّل (غير ظل) → يسجّل الدخول ويحجز من حسابه، كي لا يتوزع سجله على حسابين.
     * والجلسة المجانية مع هذا المعلم مستخدمة مسبقاً لهذا البريد → يُرفض قبل إرسال أي رمز.
     */
    private function assertEmailCanBookAsGuest(string $normalized, Package $package): void
    {
        $registered = User::where('normalized_email', $normalized)
            ->where('account_type', '!=', User::ACCOUNT_GUEST)
            ->exists();

        if ($registered) {
            throw ValidationException::withMessages([
                'email' => ['هذا البريد مسجّل لدينا — سجّل الدخول لحجز الجلسة المجانية'],
            ]);
        }

        if ($this->bookings->trialUsedWithTeacher($normalized, $package->teacher_id)) {
            throw ValidationException::withMessages([
                'email' => ['استخدمت الجلسة المجانية مع هذا المعلم مسبقاً — يحق لكل بريد جلسة مجانية واحدة مع كل معلم'],
            ]);
        }
    }
}
