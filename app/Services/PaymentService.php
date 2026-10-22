<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SupportPackagePurchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\InvalidRequestException;
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
        private readonly SupportPackagePurchaseService $supportPackagePurchaseService,
    ) {}

    public function createCheckoutSessionForBooking(Booking $booking): string
    {
        $payment = $booking->payments()->latest()->firstOrFail();
        $this->assertPayable($booking, $payment);

        if ($url = $this->reusableCheckoutUrl($payment)) {
            return $url;
        }

        $booking->loadMissing('student.user');

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            ...$this->sessionExpiry($booking),
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
            'success_url' => $this->frontendPaymentUrl('/payments/success', [
                'session_id' => '{CHECKOUT_SESSION_ID}',
                'kind' => 'booking',
                'target' => "booking-{$booking->id}",
            ]),
            'cancel_url' => $this->frontendPaymentUrl('/payments/cancel', [
                'kind' => 'booking',
                'target' => "booking-{$booking->id}",
            ]),
        ]);

        $payment->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    public function createCheckoutSessionForEnrollment(Enrollment $enrollment): string
    {
        $payment = $enrollment->payments()->latest()->firstOrFail();
        $this->assertPayable($enrollment, $payment);

        if ($url = $this->reusableCheckoutUrl($payment)) {
            return $url;
        }

        $enrollment->loadMissing('student.user');

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            ...$this->sessionExpiry($enrollment),
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
            'success_url' => $this->frontendPaymentUrl('/payments/success', [
                'session_id' => '{CHECKOUT_SESSION_ID}',
                'kind' => 'enrollment',
                'target' => "enrollment-{$enrollment->id}",
            ]),
            'cancel_url' => $this->frontendPaymentUrl('/payments/cancel', [
                'kind' => 'enrollment',
                'target' => "enrollment-{$enrollment->id}",
            ]),
        ]);

        $payment->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    public function createCheckoutSessionForSupportPackagePurchase(SupportPackagePurchase $purchase): string
    {
        $payment = $purchase->payments()->latest()->firstOrFail();
        $this->assertPayable($purchase, $payment);

        if ($url = $this->reusableCheckoutUrl($payment)) {
            return $url;
        }

        $purchase->loadMissing(['student.user', 'supportPackage']);

        $packageTitle = $purchase->supportPackage->title;

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            ...$this->sessionExpiry($purchase),
            'payment_method_types' => ['card'],
            'customer_email' => $purchase->student->user->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($purchase->currency),
                    'unit_amount' => (int) round(((float) $purchase->amount) * 100),
                    'product_data' => ['name' => "اشتراك دعم أكاديمي #{$purchase->id} - {$packageTitle}"],
                ],
            ]],
            'metadata' => ['support_package_purchase_id' => $purchase->id, 'payment_id' => $payment->id],
            'success_url' => $this->frontendPaymentUrl('/payments/success', [
                'session_id' => '{CHECKOUT_SESSION_ID}',
                'kind' => 'support_package_purchase',
                'target' => "support-package-purchase-{$purchase->id}",
            ]),
            'cancel_url' => $this->frontendPaymentUrl('/payments/cancel', [
                'kind' => 'support_package_purchase',
                'target' => "support-package-purchase-{$purchase->id}",
            ]),
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
        $payment = $this->findPaymentForSession($session);

        // مال وصل فعلاً إلى Stripe — لا يجوز أن يُتجاهَل بصمت أبداً، فكل مسار خروج مبكر هنا يُسجَّل
        if (! $payment) {
            Log::error('Stripe checkout completed for an unknown payment', $this->sessionLogContext($session));

            return;
        }

        if ($payment->status === 'paid') {
            // نفس الجلسة مرتين = إعادة إرسال عادية من Stripe؛ جلسة مختلفة = الطالب دفع مرتين لنفس الطلب
            if ($payment->stripe_session_id !== $session->id) {
                Log::error('Duplicate Stripe payment for an already paid payment', [
                    'payment_id' => $payment->id,
                    'paid_session_id' => $payment->stripe_session_id,
                    ...$this->sessionLogContext($session),
                ]);
            }

            return;
        }

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            // الجلسة التي دُفعت فعلاً قد لا تكون آخر جلسة أُنشئت (وُجدت عبر metadata)
            'stripe_session_id' => $session->id,
            'stripe_payment_intent' => $session->payment_intent ?? null,
            'gateway_payload' => (array) $session,
        ]);

        $payable = $payment->booking ?? $payment->enrollment ?? $payment->supportPackagePurchase;

        if (! $payable) {
            Log::warning('Stripe payment confirmed without a linked booking, enrollment, or purchase', ['payment_id' => $payment->id]);

            return;
        }

        $statusBefore = $payable->status;

        match (true) {
            $payable instanceof Booking => $this->bookingService->confirmBooking($payable),
            $payable instanceof Enrollment => $this->enrollmentService->confirmEnrollment($payable),
            $payable instanceof SupportPackagePurchase => $this->supportPackagePurchaseService->confirmPurchase($payable),
        };

        // دفعٌ وصل بعد أن انتهت المهلة وتحوّل الطلب إلى expired/cancelled — confirm* لا يُحييه
        // (المقعد/الكوبون ربما ذهبا لغيره)، ولا استرداد تلقائي في هذا النظام، فيُترك للإدارة
        if ($statusBefore !== 'pending_payment') {
            Log::error('Stripe payment received for a request that is no longer pending payment', [
                'payment_id' => $payment->id,
                'payable_type' => $payable::class,
                'payable_id' => $payable->id,
                'payable_status' => $statusBefore,
                ...$this->sessionLogContext($session),
            ]);
        }
    }

    /**
     * stripe_session_id يحمل آخر جلسة أُنشئت فقط؛ إن دُفعت جلسة أقدم (أو أُنشئت جلستان
     * بالتوازي) نرجع إلى payment_id المرسَل في metadata — مصدره نحن والـ webhook موقَّع.
     */
    private function findPaymentForSession(object $session): ?Payment
    {
        $query = fn () => Payment::with(['booking', 'enrollment', 'supportPackagePurchase']);

        $payment = $query()->where('stripe_session_id', $session->id)->first();

        if (! $payment && ($paymentId = $session->metadata->payment_id ?? null)) {
            $payment = $query()->find($paymentId);
        }

        return $payment;
    }

    private function sessionLogContext(object $session): array
    {
        return [
            'stripe_session_id' => $session->id,
            'stripe_payment_intent' => $session->payment_intent ?? null,
            'amount_total' => $session->amount_total ?? null,
            'metadata' => isset($session->metadata) ? $session->metadata->toArray() : [],
        ];
    }

    /**
     * لا رابط دفع لطلب لم يعد بانتظار الدفع أو انقضت مهلته — حتى لو لم يلحق المجدول
     * بتحويله إلى expired بعد. hold_expires_at فارغ = لا مهلة (طلب بلا حجز مؤقت).
     */
    private function assertPayable(Model $payable, Payment $payment): void
    {
        $holdExpired = $payable->hold_expires_at && $payable->hold_expires_at->isPast();

        if ($payable->status !== 'pending_payment' || $holdExpired) {
            throw ValidationException::withMessages([
                'payment' => ['انتهت مهلة الدفع لهذا الطلب أو لم يعد بانتظار الدفع'],
            ]);
        }

        if ($payment->status === 'paid') {
            throw ValidationException::withMessages([
                'payment' => ['تم دفع هذا الطلب مسبقاً'],
            ]);
        }
    }

    /**
     * جلسة دفع واحدة مفتوحة لكل سطر دفع: الضغط على "ادفع" مجدداً يُعيد نفس الرابط
     * بدل إنشاء جلسة جديدة تمسح القديمة (التي تبقى قابلة للدفع عند Stripe). null =
     * لا جلسة سابقة صالحة، فتُنشأ جديدة.
     */
    private function reusableCheckoutUrl(Payment $payment): ?string
    {
        if (! $payment->stripe_session_id) {
            return null;
        }

        try {
            $session = $this->stripe()->checkout->sessions->retrieve($payment->stripe_session_id);
        } catch (InvalidRequestException) {
            return null;
        }

        if ($session->status === CheckoutSession::STATUS_OPEN) {
            return $session->url;
        }

        // الطالب أكمل الدفع والـ webhook لم يصل بعد — جلسة جديدة الآن تعني دفعاً ثانياً
        if ($session->status === CheckoutSession::STATUS_COMPLETE) {
            throw ValidationException::withMessages([
                'payment' => ['تم استلام الدفع ويجري تأكيده، يرجى الانتظار قليلاً'],
            ]);
        }

        return null;
    }

    /**
     * يُغلق Stripe الجلسة عند انتهاء المهلة بدل إبقائها 24 ساعة. Stripe يشترط بين 30
     * دقيقة و24 ساعة من الإنشاء، فالمهلة الأقصر (15 دقيقة افتراضياً) تُرفع إلى الحد
     * الأدنى — الدفع المتأخر في هذه الفجوة يلتقطه handleCheckoutCompleted ويسجّله.
     */
    private function sessionExpiry(Model $payable): array
    {
        if (! $payable->hold_expires_at) {
            return [];
        }

        $expiresAt = $payable->hold_expires_at->copy()
            ->max(now()->addMinutes(31))
            ->min(now()->addHours(24)->subMinutes(5));

        return ['expires_at' => $expiresAt->timestamp];
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }

    private function frontendPaymentUrl(string $path, array $query = []): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $queryString = http_build_query($query);

        return $baseUrl.$path.($queryString !== '' ? '?'.$queryString : '');
    }
}
