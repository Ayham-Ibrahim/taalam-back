<?php

namespace Tests\Feature\Campaign;

use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\CampaignPackageAssigned;
use App\Services\BookingService;
use App\Services\CouponService;
use App\Services\PayoutService;
use App\Services\TeacherSessionsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** حسابات الحملة وباقاتها المخفضة واستبعادها من المستحقات */
class CampaignPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_from_a_campaign_link_creates_a_campaign_account(): void
    {
        $campaign = Campaign::create(['name' => 'حملة', 'slug' => 'summer']);

        $this->postJson('/api/campaigns/summer/register', [
            'name' => 'طالب حملة',
            'email' => 'promo@example.com',
            'phone' => '0500000001',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'accept_policy' => true,
        ])->assertStatus(201)
            ->assertJsonPath('data.user.account_type', 'campaign')
            ->assertJsonPath('data.user.is_from_campaign', true);

        $user = User::where('email', 'promo@example.com')->sole();
        $this->assertSame($campaign->id, $user->campaign_id);
        $this->assertNotNull($user->student);
    }

    public function test_a_closed_campaign_rejects_registration(): void
    {
        Campaign::create(['name' => 'حملة', 'slug' => 'old', 'is_active' => false]);

        $this->postJson('/api/campaigns/old/register', [
            'name' => 'طالب', 'email' => 'promo@example.com', 'phone' => '0500000001',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_policy' => true,
        ])->assertStatus(422);
    }

    public function test_admin_creates_campaign_package_and_teacher_is_notified(): void
    {
        Notification::fake();
        $teacher = $this->teacher();
        AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => 3]);
        $admin = User::factory()->admin()->create();

        $this->as($admin->createToken('t')->plainTextToken)
            ->postJson('/api/admin/campaign-packages', [
                'teacher_id' => $teacher->id,
                'title' => 'باقة الحملة',
                'sessions_count' => 5,
                'price' => 150,
                'schedules' => [['day_of_week' => 3]],
            ])->assertStatus(201)
            ->assertJsonPath('data.audience', 'campaign')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.student_price', 150);

        $package = Package::sole();
        $this->assertEquals(0.0, (float) $package->teacher_price);
        $this->assertEquals(150.0, (float) $package->platform_revenue);
        $this->assertSame('individual', $package->session_format);

        Notification::assertSentTo(User::find($teacher->user_id), CampaignPackageAssigned::class);

        // التعطيل للأدمن وحده
        $this->as(User::find($teacher->user_id)->createToken('t')->plainTextToken)
            ->postJson("/api/packages/{$package->id}/disable")
            ->assertStatus(403);
    }

    public function test_campaign_packages_are_hidden_from_everyone_but_campaign_accounts(): void
    {
        $teacher = $this->teacher();
        $public = $this->publicPackage($teacher);
        $campaignPackage = $this->campaignPackage($teacher);

        $ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$public->id], $ids($this->getJson("/api/teachers/{$teacher->id}/packages")));

        $regular = $this->student();
        $this->assertSame([$public->id], $ids($this->as($regular->user->createToken('t')->plainTextToken)->getJson("/api/teachers/{$teacher->id}/packages")));

        $campaignStudent = $this->campaignStudent();
        $token = $campaignStudent->user->createToken('t')->plainTextToken;
        $this->assertEqualsCanonicalizing([$public->id, $campaignPackage->id], $ids($this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")));

        $this->as($token)->getJson('/api/campaign-packages')
            ->assertOk()
            ->assertJsonPath('data.right_used', false)
            ->assertJsonPath('data.packages.0.id', $campaignPackage->id);

        $this->as($regular->user->createToken('t2')->plainTextToken)->getJson('/api/campaign-packages')->assertStatus(403);
    }

    public function test_campaign_account_books_one_campaign_package_only_and_no_public_paid_package_until_converted(): void
    {
        $teacher = $this->teacher();
        $public = $this->publicPackage($teacher);
        $first = $this->campaignPackage($teacher);
        $second = $this->campaignPackage($teacher);
        $student = $this->campaignStudent();
        $service = app(BookingService::class);
        $day = Carbon::now()->next(3);

        try {
            $service->requestIndividualBooking($student, $public, $this->slots($day, 1));
            $this->fail('حساب الحملة غير المحوَّل حجز باقة عادية');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account', $e->errors());
        }

        $booking = $service->requestIndividualBooking($student, $first, $this->slots($day, 5));
        $this->assertTrue($booking->excluded_from_payouts);
        $this->assertEquals(0.0, (float) $booking->teacher_amount);
        $this->assertEquals(150.0, (float) $booking->platform_amount);

        try {
            $service->requestIndividualBooking($student, $second, $this->slots($day->copy()->addWeeks(6), 5));
            $this->fail('حجز باقة حملة ثانية');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package', $e->errors());
        }

        // بعد التحويل: يحجز العادية، والحق في الحملة يبقى مستخدَماً لا يتجدد
        $this->as($student->user->createToken('t')->plainTextToken)
            ->postJson('/api/me/convert-account')
            ->assertOk()
            ->assertJsonPath('data.account_type', 'regular')
            ->assertJsonPath('data.is_from_campaign', true);

        $student->user->refresh();
        $this->assertSame('pending_teacher_confirmation', $service->requestIndividualBooking($student->fresh(), $public, $this->slots($day->copy()->addWeeks(12), 1))->status);
    }

    public function test_converted_account_keeps_its_unused_campaign_right(): void
    {
        $teacher = $this->teacher();
        $campaignPackage = $this->campaignPackage($teacher);
        $student = $this->campaignStudent();
        $student->user->update(['account_type' => User::ACCOUNT_REGULAR, 'converted_at' => now()]);

        $booking = app(BookingService::class)->requestIndividualBooking($student->fresh(), $campaignPackage, $this->slots(Carbon::now()->next(3), 5));

        $this->assertSame('pending_payment', $booking->status);
    }

    public function test_admin_sets_campaign_windows_with_hours_regardless_of_teacher_availability(): void
    {
        Notification::fake();
        $teacher = $this->teacher();
        $admin = User::factory()->admin()->create(['timezone' => 'Asia/Dubai']);

        // المعلم بلا أي أيام توفّر — الأدمن يحدد النوافذ بنفسه
        $this->as($admin->createToken('t')->plainTextToken)
            ->postJson('/api/admin/campaign-packages', [
                'teacher_id' => $teacher->id,
                'title' => 'باقة الحملة',
                'sessions_count' => 2,
                'price' => 100,
                'schedules' => [
                    ['day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '20:00'],
                    ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00'],
                    ['day_of_week' => 5],
                ],
            ])->assertStatus(201)
            ->assertJsonPath('data.schedule_timezone', 'Asia/Dubai')
            ->assertJsonCount(3, 'data.schedules');

        $this->as($admin->createToken('t2')->plainTextToken)
            ->postJson('/api/admin/campaign-packages', [
                'teacher_id' => $teacher->id, 'title' => 'ضيقة', 'sessions_count' => 1, 'price' => 100,
                'schedules' => [['day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '16:30']],
            ])->assertStatus(422);
    }

    public function test_campaign_booking_skips_teacher_approval_and_respects_windows_and_conflicts(): void
    {
        $teacher = $this->teacher();
        $package = $this->campaignPackage($teacher, sessions: 1);
        $package->schedules()->delete();
        $package->schedules()->create(['day_of_week' => 3, 'start_time' => '16:00', 'end_time' => '20:00']);
        $service = app(BookingService::class);
        $day = Carbon::now()->next(3)->toDateString();

        // خارج النافذة، وجلسة تتجاوز نهايتها (19:30 + 60 دقيقة)، ويوم غير متاح
        foreach (['15:00', '19:30'] as $time) {
            try {
                $service->requestIndividualBooking($this->campaignStudent(), $package, [['date' => $day, 'start_time' => $time]]);
                $this->fail("قُبل موعد خارج النافذة: {$time}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slots', $e->errors());
            }
        }

        $first = $this->campaignStudent();
        $booking = $service->requestIndividualBooking($first, $package, [['date' => $day, 'start_time' => '17:00']]);

        $this->assertSame('pending_payment', $booking->status);
        $this->assertNotNull($booking->hold_expires_at);
        $this->assertCount(1, $booking->sessions);
        $this->assertSame('pending', $booking->payments()->sole()->status);

        // نفس موعد المعلم لطالب آخر — تعارض
        try {
            $service->requestIndividualBooking($this->campaignStudent(), $package, [['date' => $day, 'start_time' => '17:30']]);
            $this->fail('حُجز موعد يتعارض مع جلسة المعلم');
        } catch (ValidationException) {
            $this->assertSame(1, ClassSession::count());
        }
    }

    public function test_regular_student_cannot_book_campaign_package_nor_use_coupons_on_it(): void
    {
        $teacher = $this->teacher();
        $campaignPackage = $this->campaignPackage($teacher);

        try {
            app(BookingService::class)->requestIndividualBooking($this->student(), $campaignPackage, $this->slots(Carbon::now()->next(3), 5));
            $this->fail('طالب عادي حجز باقة حملة');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package', $e->errors());
        }

        $this->expectException(ValidationException::class);
        app(CouponService::class)->create($campaignPackage, User::factory()->admin()->create(), ['discount_type' => 'percent', 'discount_value' => 10]);
    }

    public function test_campaign_sessions_are_excluded_from_payouts_but_count_as_platform_revenue(): void
    {
        $teacher = $this->teacher();
        $campaignPackage = $this->campaignPackage($teacher);
        $student = $this->campaignStudent();

        $booking = Booking::create([
            'reference' => 'BK-CAMPAIGN1', 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'package_id' => $campaignPackage->id, 'amount_paid' => 150, 'teacher_amount' => 0,
            'platform_amount' => 150, 'margin_percent_snapshot' => 100, 'excluded_from_payouts' => true,
            'sessions_total' => 5, 'sessions_used' => 0, 'sessions_remaining' => 5,
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        ClassSession::create([
            'booking_id' => $booking->id, 'teacher_id' => $teacher->id, 'sequence_no' => 1,
            'scheduled_at' => now()->subDays(2), 'duration_min' => 60, 'status' => 'completed',
            'attendance_outcome' => ClassSession::OUTCOME_ATTENDED, 'teacher_payout_percent' => 100,
        ]);

        try {
            app(PayoutService::class)->generateForPeriod($teacher, null, now());
            $this->fail('كشف مستحقات لجلسة حملة');
        } catch (ValidationException) {
            $this->assertSame(0, \App\Models\Payout::count());
        }

        $row = app(TeacherSessionsReportService::class)->getReport(null, null, $teacher->id)['rows'][0];
        $this->assertSame(1, $row['campaignSessionsCount']);
        $this->assertSame(0, $row['sessionsCount']);
        $this->assertEquals(0.0, $row['teacherRevenue']);
        $this->assertEquals(30.0, $row['platformRevenue']);
    }

    private function teacher(): Teacher
    {
        $user = User::factory()->teacher()->create(['timezone' => 'UTC']);

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
    }

    private function student(): Student
    {
        $user = User::factory()->student()->create(['timezone' => 'UTC']);

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function campaignStudent(): Student
    {
        $campaign = Campaign::firstOrCreate(['slug' => 'summer'], ['name' => 'حملة']);
        $user = User::factory()->student()->create([
            'timezone' => 'UTC', 'account_type' => User::ACCOUNT_CAMPAIGN, 'campaign_id' => $campaign->id,
        ]);

        return Student::create(['user_id' => $user->id]);
    }

    private function publicPackage(Teacher $teacher): Package
    {
        $package = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'عادية', 'session_format' => 'individual', 'capacity' => 1,
            'sessions_count' => 1, 'teacher_price' => 100, 'platform_margin_percent' => 20,
            'student_price' => 120, 'platform_revenue' => 20, 'status' => 'active', 'approved_at' => now(),
        ]);
        $package->schedules()->create(['day_of_week' => 3]);

        return $package;
    }

    private function campaignPackage(Teacher $teacher, int $sessions = 5): Package
    {
        $package = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'حملة', 'session_format' => 'individual', 'capacity' => 1,
            'audience' => Package::AUDIENCE_CAMPAIGN, 'sessions_count' => $sessions, 'teacher_price' => 0,
            'platform_margin_percent' => 100, 'student_price' => 150, 'platform_revenue' => 150,
            'status' => 'active', 'approved_at' => now(),
        ]);
        $package->schedules()->create(['day_of_week' => 3]);

        return $package;
    }

    /** مواعيد أسبوعية متتالية يوم الأربعاء */
    private function slots(Carbon $firstWednesday, int $count): array
    {
        return collect(range(0, $count - 1))
            ->map(fn (int $i) => ['date' => $firstWednesday->copy()->addWeeks($i)->toDateString(), 'start_time' => '10:00'])
            ->all();
    }
}
