<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * بوابة الدفع الوحيدة. إنشاء جلسة الدفع خارج معاملة قاعدة البيانات عمداً —
 * نداء HTTP خارجي لا يجب أن يُبقي قفل معاملة مفتوحاً. BookingService/EnrollmentService
 * ينشئان سطر Payment محلياً (queued/pending)، وهذه الخدمة تُكمِّله بجلسة Stripe فعلية.
 *
 * ⚠️ لا مفاتيح Stripe حقيقية في هذه البيئة — الكود يتبع توثيق Stripe الرسمي حرفياً
 * لكنه غير مُختبَر ضد خادم Stripe فعلي.
 */
class PaymentService
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly EnrollmentService $enrollmentService,
    ) {}

    public function createCheckoutSessionForBooking(Booking $booking): string
    {
        $payment = $booking->payments()->latest()->firstOrFail();
        $booking->loadMissing('student.user');

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $booking->student->user->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($booking->currency),
                    'unit_amount' => (int) round(((float) $booking->amount_paid) * 100),
                    'product_data' => ['name' => "حجز باقة #{$booking->reference}"],
                ],
            ]],
            'metadata' => ['booking_id' => $booking->id, 'payment_id' => $payment->id],
            'success_url' => config('app.url').'/payments/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url').'/payments/cancel',
        ]);

        $payment->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    public function createCheckoutSessionForEnrollment(Enrollment $enrollment): string
    {
        $payment = $enrollment->payments()->latest()->firstOrFail();
        $enrollment->loadMissing('student.user');

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $enrollment->student->user->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($enrollment->currency),
                    'unit_amount' => (int) round(((float) $enrollment->amount_paid) * 100),
                    'product_data' => ['name' => "تسجيل دورة #{$enrollment->reference}"],
                ],
            ]],
            'metadata' => ['enrollment_id' => $enrollment->id, 'payment_id' => $payment->id],
            'success_url' => config('app.url').'/payments/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url').'/payments/cancel',
        ]);

        $payment->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    /**
     * @throws UnexpectedValueException|SignatureVerificationException عند فشل التحقق من التوقيع
     */
    public function handleWebhook(string $payload, string $signature): void
    {
        $event = Webhook::constructEvent($payload, $signature, (string) config('services.stripe.webhook_secret'));

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event->data->object);
        }
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $payment = Payment::with(['booking', 'enrollment'])->where('stripe_session_id', $session->id)->first();

        if (! $payment || $payment->status === 'paid') {
            return;
        }

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'stripe_payment_intent' => $session->payment_intent ?? null,
            'gateway_payload' => (array) $session,
        ]);

        if ($payment->booking_id) {
            $this->bookingService->confirmBooking($payment->booking);
        } elseif ($payment->enrollment_id) {
            $this->enrollmentService->confirmEnrollment($payment->enrollment);
        } else {
            Log::warning('Stripe payment confirmed without a linked booking or enrollment', ['payment_id' => $payment->id]);
        }
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
