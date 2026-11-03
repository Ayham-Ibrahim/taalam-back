<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\MetaConversionsService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * أحداث Meta Conversions API من السيرفر: CompleteRegistration عند تسجيل الطالب وPurchase
 * عند تأكيد Stripe للدفع — بنفس event_id الذي يرسله البكسل من المتصفح كي تحذف Meta المكرر.
 */
class MetaConversionsTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.meta.pixel_id' => '111',
            'services.meta.access_token' => 'token_test',
            'services.meta.test_event_code' => 'TEST123',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    }

    public function test_student_registration_sends_complete_registration_with_hashed_user_data(): void
    {
        $response = $this->withHeaders(['X-Meta-Fbp' => 'fb.1.1700000000.42', 'X-Meta-Source-Url' => 'https://t3allem.com/register'])
            ->postJson('/api/auth/register/student', $this->registrationPayload(['email' => ' Ahmad@Example.com', 'phone' => '0501234567']));

        $response->assertCreated();
        $userId = $response->json('data.user.id');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($userId) {
            $event = $request['data'][0];

            return str_contains($request->url(), '/111/events')
                && $request['access_token'] === 'token_test'
                && $request['test_event_code'] === 'TEST123'
                && $event['event_name'] === 'CompleteRegistration'
                && $event['event_id'] === "registration_{$userId}"
                && $event['event_source_url'] === 'https://t3allem.com/register'
                && $event['user_data']['em'] === [hash('sha256', 'ahmad@example.com')]
                && $event['user_data']['ph'] === [hash('sha256', '971501234567')]
                && $event['user_data']['fbp'] === 'fb.1.1700000000.42';
        });
    }

    public function test_nothing_is_sent_while_the_pixel_is_not_configured(): void
    {
        config(['services.meta.pixel_id' => null, 'services.meta.access_token' => null]);

        $this->postJson('/api/auth/register/student', $this->registrationPayload())->assertCreated();

        [, $payment] = $this->pendingBooking();
        $this->sendWebhook(['id' => 'cs_A', 'metadata' => ['payment_id' => (string) $payment->id]]);

        Http::assertNothingSent();
    }

    public function test_confirmed_payment_sends_one_purchase_with_the_browser_context_saved_at_checkout(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $payment->update(['meta_context' => [
            'client_ip_address' => '94.200.10.5',
            'client_user_agent' => 'Mozilla/5.0',
            'fbc' => 'fb.1.1700000000.IwAR',
            'event_source_url' => 'https://t3allem.com/teacher/1',
            'checkout_tracked' => true,
        ]]);

        $session = ['id' => 'cs_A', 'metadata' => ['payment_id' => (string) $payment->id]];
        $this->sendWebhook($session);
        // إعادة إرسال Stripe لنفس الحدث لا تُنتج Purchase ثانياً
        $this->sendWebhook($session);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($booking) {
            $event = $request['data'][0];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === "purchase_booking-{$booking->id}"
                && $event['custom_data']['value'] === 160.0
                && $event['custom_data']['currency'] === 'AED'
                && $event['custom_data']['content_ids'] === ["booking-{$booking->id}"]
                && $event['user_data']['client_ip_address'] === '94.200.10.5'
                && $event['user_data']['fbc'] === 'fb.1.1700000000.IwAR'
                && ! isset($event['user_data']['event_source_url'])
                && ! isset($event['user_data']['checkout_tracked']);
        });
    }

    public function test_initiate_checkout_is_sent_once_per_order_with_the_same_event_id_as_the_pixel(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $meta = app(MetaConversionsService::class);

        $meta->trackCheckoutStarted($payment, $booking, $this->checkoutRequest(['X-Meta-Fbp' => 'fb.1.1.A', 'X-Meta-Fbc' => 'fb.1.1.CLICK']));
        // الطالب رجع من Stripe وضغط "ادفع" مجدداً — من متصفح بلا _fbc هذه المرة
        $meta->trackCheckoutStarted($payment->fresh(), $booking, $this->checkoutRequest(['X-Meta-Fbp' => 'fb.1.1.A']));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['data'][0]['event_name'] === 'InitiateCheckout'
            && $request['data'][0]['event_id'] === "checkout_booking-{$booking->id}"
            && $request['data'][0]['custom_data']['value'] === 160.0
            && $request['data'][0]['user_data']['fbc'] === 'fb.1.1.CLICK');

        // ما التُقط في الضغطة الأولى يبقى لحدث Purchase
        $this->assertSame('fb.1.1.CLICK', $payment->fresh()->meta_context['fbc']);
    }

    public function test_checkout_tracking_failure_never_breaks_checkout(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        // أي فشل في الحفظ — مثل تفعيل التتبع في .env قبل تشغيل الـ migration
        Payment::updating(fn () => throw new \RuntimeException('Unknown column meta_context'));
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'checkout tracking failed'));

        app(MetaConversionsService::class)->trackCheckoutStarted($payment, $booking, $this->checkoutRequest());

        Http::assertNothingSent();
    }

    private function checkoutRequest(array $headers = []): HttpRequest
    {
        $request = HttpRequest::create('/api/bookings/1/checkout', 'POST', server: ['REMOTE_ADDR' => '94.200.10.5']);
        $request->headers->add($headers);

        return $request;
    }

    public function test_a_failing_meta_api_never_breaks_payment_confirmation(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid']], 400)]);

        [$booking, $payment] = $this->pendingBooking();
        $this->sendWebhook(['id' => 'cs_A', 'metadata' => ['payment_id' => (string) $payment->id]]);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    private function registrationPayload(array $overrides = []): array
    {
        $curriculumId = DB::table('curricula')->insertGetId([
            'code' => 'c-'.uniqid(), 'name_ar' => 'وطني', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stageId = DB::table('stages')->insertGetId([
            'code' => 's-'.uniqid(), 'name_ar' => 'ابتدائي', 'education_type' => 'school', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'name' => 'أحمد محمد',
            'email' => 'student-'.uniqid().'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'education_type' => 'school',
            'curriculum_id' => $curriculumId,
            'stage_id' => $stageId,
            'grade' => 5,
            ...$overrides,
        ];
    }

    private function sendWebhook(array $session): void
    {
        $payload = json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['object' => 'checkout.session', ...$session]],
        ]);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        app(PaymentService::class)->handleWebhook($payload, $signature);
    }

    /** @return array{0: Booking, 1: Payment} */
    private function pendingBooking(): array
    {
        $subject = Subject::create(['code' => 'meta-'.uniqid(), 'name_ar' => 'مادة']);
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->addMinutes(15),
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'amount' => 160,
            'currency' => 'AED',
            'method' => 'stripe',
            'status' => 'pending',
        ]);

        return [$booking, $payment];
    }
}
