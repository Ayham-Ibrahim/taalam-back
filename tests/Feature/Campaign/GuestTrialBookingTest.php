<?php

namespace Tests\Feature\Campaign;

use App\Models\Booking;
use App\Models\GuestEmailVerification;
use App\Models\Package;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\GuestTrialBookingStatus;
use App\Notifications\GuestVerificationCode;
use App\Services\AuthService;
use App\Services\BookingService;
use App\Support\EmailNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** حجز الباقة المجانية بلا حساب، وقيد "مرة لكل بريد مع كل معلم" المشترك مع الطلاب المسجّلين */
class GuestTrialBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_normalizer_closes_alias_variants(): void
    {
        $this->assertSame('ali@gmail.com', EmailNormalizer::normalize(' A.Li+promo@GoogleMail.com '));
        $this->assertSame('ali@site.com', EmailNormalizer::normalize('Ali+1@Site.com'));
        $this->assertSame('a.li@site.com', EmailNormalizer::normalize('a.li@site.com'));
    }

    public function test_guest_books_trial_with_verified_email_and_gets_a_shadow_account(): void
    {
        Notification::fake();
        [$teacher, $package, $day] = $this->trialSetup();

        $code = $this->requestCode($package, 'Visitor@Example.com');

        $this->postJson("/api/packages/{$package->id}/guest-trial/bookings", $this->bookingPayload('Visitor@Example.com', $code, $day))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_teacher_confirmation');

        $guest = User::where('email', 'Visitor@Example.com')->sole();
        $this->assertSame(User::ACCOUNT_GUEST, $guest->account_type);
        $this->assertNull($guest->password);
        $this->assertSame('visitor@example.com', $guest->normalized_email);
        $this->assertSame(1, Booking::where('student_id', $guest->student->id)->count());
        $this->assertNotNull(GuestEmailVerification::sole()->used_at);

        Notification::assertSentTo($guest, GuestTrialBookingStatus::class);

        // الرمز يُستهلك مرة واحدة
        $this->postJson("/api/packages/{$package->id}/guest-trial/bookings", $this->bookingPayload('Visitor@Example.com', $code, $day))
            ->assertStatus(422);
    }

    public function test_wrong_code_is_rejected_and_counted(): void
    {
        Notification::fake();
        [, $package, $day] = $this->trialSetup();

        $code = $this->requestCode($package, 'visitor@example.com');
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->postJson("/api/packages/{$package->id}/guest-trial/bookings", $this->bookingPayload('visitor@example.com', $wrong, $day))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, GuestEmailVerification::sole()->attempts);
        $this->assertSame(0, User::where('email', 'visitor@example.com')->count());
    }

    public function test_same_email_alias_cannot_take_a_second_trial_with_the_same_teacher_but_can_with_another(): void
    {
        Notification::fake();
        [$teacher, $package, $day] = $this->trialSetup();

        $code = $this->requestCode($package, 'visitor@gmail.com');
        $this->postJson("/api/packages/{$package->id}/guest-trial/bookings", $this->bookingPayload('visitor@gmail.com', $code, $day))
            ->assertStatus(201);

        $this->postJson("/api/packages/{$package->id}/guest-trial/code", ['email' => 'vis.itor+2@gmail.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        [, $otherPackage] = $this->trialSetup();
        $this->travel(2)->minutes();

        $this->postJson("/api/packages/{$otherPackage->id}/guest-trial/code", ['email' => 'vis.itor+2@gmail.com'])
            ->assertOk();
    }

    public function test_registered_email_must_log_in_instead(): void
    {
        Notification::fake();
        [, $package] = $this->trialSetup();
        User::factory()->student()->create(['email' => 'member@example.com']);

        $this->postJson("/api/packages/{$package->id}/guest-trial/code", ['email' => 'Member+x@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_paid_packages_cannot_be_booked_as_guest(): void
    {
        [$teacher] = $this->trialSetup();
        $paid = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'مدفوعة', 'session_format' => 'individual', 'capacity' => 1,
            'sessions_count' => 1, 'teacher_price' => 100, 'platform_margin_percent' => 20,
            'student_price' => 120, 'platform_revenue' => 20, 'status' => 'active', 'approved_at' => now(),
        ]);

        $this->postJson("/api/packages/{$paid->id}/guest-trial/code", ['email' => 'visitor@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('package');
    }

    public function test_sibling_trial_counts_against_the_primary_account_email(): void
    {
        [$teacher, $package, $day] = $this->trialSetup();
        $primary = $this->student('family@example.com');
        $siblingUser = User::create(['name' => 'أخ', 'email' => null, 'role' => 'student', 'primary_user_id' => $primary->user_id]);
        $sibling = Student::create(['user_id' => $siblingUser->id]);

        $service = app(BookingService::class);
        $service->requestIndividualBooking($primary, $package, [['date' => $day->toDateString(), 'start_time' => '10:00']]);

        $this->expectException(ValidationException::class);
        $service->requestIndividualBooking($sibling, $package, [['date' => $day->copy()->addWeek()->toDateString(), 'start_time' => '10:00']]);
    }

    public function test_a_rejected_trial_request_does_not_consume_the_right(): void
    {
        [$teacher, $package, $day] = $this->trialSetup();
        $student = $this->student('member@example.com');
        $service = app(BookingService::class);

        $first = $service->requestIndividualBooking($student, $package, [['date' => $day->toDateString(), 'start_time' => '10:00']]);
        $service->rejectIndividualRequest($first, User::find($teacher->user_id), 'وقت غير مناسب');

        $second = $service->requestIndividualBooking($student, $package, [['date' => $day->toDateString(), 'start_time' => '12:00']]);

        $this->assertSame('pending_teacher_confirmation', $second->status);
    }

    /** capacity=1 للفردية لكل جلسة لا للباقة — كان ثاني تأكيد يكسر chk_packages_enrolled */
    public function test_a_second_student_can_be_approved_on_the_same_individual_package(): void
    {
        [$teacher, $package, $day] = $this->trialSetup();
        $teacherUser = User::find($teacher->user_id);
        $service = app(BookingService::class);

        foreach (['first@example.com' => '10:00', 'second@example.com' => '12:00'] as $email => $time) {
            $request = $service->requestIndividualBooking($this->student($email), $package, [['date' => $day->toDateString(), 'start_time' => $time]]);
            $this->assertSame('confirmed', $service->approveIndividualRequest($request, $teacherUser)->status);
        }

        $this->assertSame(1, $package->fresh()->enrolled_count);
    }

    public function test_guest_registering_later_upgrades_the_same_account(): void
    {
        Notification::fake();
        [, $package, $day] = $this->trialSetup();

        $code = $this->requestCode($package, 'visitor@example.com');
        $this->postJson("/api/packages/{$package->id}/guest-trial/bookings", $this->bookingPayload('visitor@example.com', $code, $day))
            ->assertStatus(201);
        $guestId = User::where('email', 'visitor@example.com')->value('id');

        // بريد حساب الظل لا يُرفض كمستخدم في طلب التسجيل
        $errors = $this->postJson('/api/auth/register/student', ['email' => 'visitor@example.com'])->json('errors') ?? [];
        $this->assertArrayNotHasKey('email', $errors);

        $result = app(AuthService::class)->registerStudent([
            'name' => 'زائر صار طالباً',
            'email' => 'visitor@example.com',
            'password' => 'Secret123',
            'education_type' => 'school',
        ]);

        $this->assertSame($guestId, $result['user']->id);
        $this->assertSame(User::ACCOUNT_REGULAR, $result['user']->fresh()->account_type);
        $this->assertSame(1, User::where('email', 'visitor@example.com')->count());
        $this->assertSame(1, Booking::where('student_id', $result['user']->student->id)->count());
    }

    /** @return array{0: Teacher, 1: Package, 2: Carbon} */
    private function trialSetup(): array
    {
        $teacherUser = User::factory()->teacher()->create(['timezone' => 'UTC']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $package = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'جلسة تعريفية مجانية', 'session_format' => 'individual',
            'is_trial' => true, 'capacity' => 1, 'sessions_count' => 1, 'teacher_price' => 0,
            'platform_margin_percent' => 0, 'student_price' => 0, 'platform_revenue' => 0,
            'status' => 'active', 'approved_at' => now(),
        ]);

        $day = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $day->dayOfWeek]);

        return [$teacher, $package, $day];
    }

    private function student(string $email): Student
    {
        $user = User::factory()->student()->create(['email' => $email, 'timezone' => 'UTC']);

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function requestCode(Package $package, string $email): string
    {
        $this->postJson("/api/packages/{$package->id}/guest-trial/code", ['email' => $email])->assertOk();

        $code = null;
        Notification::assertSentOnDemand(GuestVerificationCode::class, function (GuestVerificationCode $notification) use (&$code) {
            $code = (fn () => $this->code)->call($notification);

            return true;
        });

        return $code;
    }

    private function bookingPayload(string $email, string $code, Carbon $day): array
    {
        return [
            'email' => $email,
            'code' => $code,
            'name' => 'زائر',
            'timezone' => 'UTC',
            'slots' => [['date' => $day->toDateString(), 'start_time' => '10:00']],
        ];
    }
}
