<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * كل ضغطة على "ادفع" كانت تُنشئ جلسة Stripe جديدة وتكتب رقمها فوق stripe_session_id،
 * فالدفع من الرابط الأقدم (الصالح عند Stripe 24 ساعة) يصل للـ webhook فلا يجد سطر
 * الدفع ويضيع المبلغ بصمت. ولم يكن checkout يتحقق من hold_expires_at إطلاقاً.
 */
class CheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test';

    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);

        $this->http = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_clicking_pay_again_reuses_the_still_open_session(): void
    {
        [$booking, $payment] = $this->pendingBooking();

        $this->http->respond(['id' => 'cs_A', 'url' => 'https://stripe.test/A', 'status' => 'open']);
        $first = $this->service()->createCheckoutSessionForBooking($booking);

        $this->http->respond(['id' => 'cs_A', 'url' => 'https://stripe.test/A', 'status' => 'open']);
        $second = $this->service()->createCheckoutSessionForBooking($booking->fresh());

        $this->assertSame('https://stripe.test/A', $first);
        $this->assertSame($first, $second);
        $this->assertSame('cs_A', $payment->fresh()->stripe_session_id);
        $this->assertSame(['POST', 'GET'], array_column($this->http->requests, 'method'));
    }

    public function test_a_new_session_is_created_once_the_previous_one_expired(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $payment->update(['stripe_session_id' => 'cs_OLD']);

        $this->http->respond(['id' => 'cs_OLD', 'url' => null, 'status' => 'expired']);
        $this->http->respond(['id' => 'cs_NEW', 'url' => 'https://stripe.test/NEW', 'status' => 'open']);

        $url = $this->service()->createCheckoutSessionForBooking($booking);

        $this->assertSame('https://stripe.test/NEW', $url);
        $this->assertSame('cs_NEW', $payment->fresh()->stripe_session_id);
    }

    public function test_a_completed_session_awaiting_its_webhook_blocks_a_second_payment(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $payment->update(['stripe_session_id' => 'cs_PAID']);

        $this->http->respond(['id' => 'cs_PAID', 'url' => null, 'status' => 'complete']);

        $this->expectException(ValidationException::class);

        $this->service()->createCheckoutSessionForBooking($booking);
    }

    public function test_checkout_is_refused_once_the_hold_has_expired_even_before_the_scheduler_runs(): void
    {
        [$booking] = $this->pendingBooking(holdExpiresAt: now()->subMinute());

        try {
            $this->service()->createCheckoutSessionForBooking($booking);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame([], $this->http->requests);
        }
    }

    public function test_the_new_session_expires_no_earlier_than_stripes_30_minute_minimum(): void
    {
        [$booking] = $this->pendingBooking(holdExpiresAt: now()->addMinutes(15));

        $this->http->respond(['id' => 'cs_A', 'url' => 'https://stripe.test/A', 'status' => 'open']);
        $this->service()->createCheckoutSessionForBooking($booking);

        $expiresAt = $this->http->requests[0]['params']['expires_at'];
        $this->assertGreaterThanOrEqual(now()->addMinutes(30)->timestamp, $expiresAt);
        $this->assertLessThan(now()->addMinutes(40)->timestamp, $expiresAt);
    }

    public function test_paying_an_older_session_is_matched_through_metadata_and_confirms_the_booking(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $payment->update(['stripe_session_id' => 'cs_NEWER']);

        $this->sendWebhook(['id' => 'cs_OLDER', 'payment_intent' => 'pi_1', 'metadata' => ['payment_id' => (string) $payment->id]]);

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('cs_OLDER', $payment->stripe_session_id);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_a_second_paid_session_for_an_already_paid_payment_is_logged_as_duplicate(): void
    {
        [, $payment] = $this->pendingBooking();
        $payment->update(['stripe_session_id' => 'cs_A', 'status' => 'paid', 'paid_at' => now()]);

        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'Duplicate'));

        $this->sendWebhook(['id' => 'cs_B', 'metadata' => ['payment_id' => (string) $payment->id]]);

        $this->assertSame('cs_A', $payment->fresh()->stripe_session_id);
    }

    public function test_a_payment_arriving_after_the_booking_expired_is_recorded_and_logged(): void
    {
        [$booking, $payment] = $this->pendingBooking();
        $booking->update(['status' => 'expired']);

        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'no longer pending payment'));

        $this->sendWebhook(['id' => 'cs_LATE', 'metadata' => ['payment_id' => (string) $payment->id]]);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('expired', $booking->fresh()->status);
    }

    public function test_an_unknown_session_is_logged_instead_of_silently_ignored(): void
    {
        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'unknown payment'));

        $this->sendWebhook(['id' => 'cs_GHOST', 'metadata' => []]);
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
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

        $this->service()->handleWebhook($payload, $signature);
    }

    /** @return array{0: Booking, 1: Payment} */
    private function pendingBooking(mixed $holdExpiresAt = 'default'): array
    {
        $subject = Subject::create(['code' => 'pay-'.uniqid(), 'name_ar' => 'مادة']);
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
            'hold_expires_at' => $holdExpiresAt === 'default' ? now()->addMinutes(15) : $holdExpiresAt,
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'amount' => 160,
            'currency' => 'USD',
            'method' => 'stripe',
            'status' => 'pending',
        ]);

        return [$booking, $payment];
    }
}

/** يردّ على طلبات Stripe SDK بالترتيب من طابور محلي ويسجّلها — بلا أي اتصال شبكي. */
class FakeStripeHttpClient implements ClientInterface
{
    public array $requests = [];

    private array $responses = [];

    public function respond(array $session): void
    {
        $this->responses[] = ['object' => 'checkout.session', ...$session];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => strtoupper($method), 'url' => $absUrl, 'params' => $params];

        $body = array_shift($this->responses) ?? throw new \RuntimeException("Unexpected Stripe request: {$method} {$absUrl}");

        return [json_encode($body), 200, []];
    }
}
