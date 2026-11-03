<?php

namespace App\Services;

use App\Jobs\SendMetaConversionEventJob;
use App\Models\Booking;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta Conversions API — نسخة السيرفر من أحداث البكسل (CompleteRegistration وPurchase).
 * كل حدث يحمل نفس event_id الذي يرسله البكسل من المتصفح، فتحذف Meta المكرر وتُبقي واحداً.
 *
 * معطّلة بالكامل ما دام META_PIXEL_ID أو META_CAPI_ACCESS_TOKEN فارغاً. أي فشل هنا يُسجَّل
 * فقط — تتبع الإعلانات لا يجوز أبداً أن يُفشل التسجيل أو تأكيد الدفع.
 */
class MetaConversionsService
{
    public function enabled(): bool
    {
        return filled(config('services.meta.pixel_id')) && filled(config('services.meta.access_token'));
    }

    /**
     * بيانات المتصفح التي يرسلها الفرونت كترويسات (X-Meta-*) مع طلبات الـ API —
     * الـ API على نطاق مختلف عن الموقع، فكوكيز البكسل لا تصله تلقائياً.
     */
    public function browserContext(Request $request): array
    {
        return array_filter([
            'client_ip_address' => $request->ip(),
            'client_user_agent' => $request->userAgent(),
            'fbp' => $request->header('X-Meta-Fbp'),
            'fbc' => $request->header('X-Meta-Fbc'),
            'event_source_url' => $request->header('X-Meta-Source-Url'),
        ]);
    }

    /**
     * عند ضغط "ادفع": يحفظ بيانات متصفح الطالب لحدث Purchase اللاحق (الـ webhook يأتي من
     * Stripe لا من الطالب فلا يحملها)، ويرسل InitiateCheckout — المتصفح قد يغادر إلى Stripe
     * قبل أن يكتمل طلب البكسل، فهذه النسخة تضمن وصوله.
     *
     * مرة واحدة لكل طلب: event_id = checkout_{target} مثل البكسل تماماً، وcheckout_tracked
     * يمنع إعادة الإرسال عند ضغط "ادفع" مجدداً لنفس الطلب. لا يُفشل الدفع أبداً.
     */
    public function trackCheckoutStarted(Payment $payment, Model $payable, Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $previous = $payment->meta_context ?? [];
            $alreadyTracked = (bool) ($previous['checkout_tracked'] ?? false);
            $context = $this->browserContext($request);

            // ضغطة لاحقة بلا كوكي _fbc مثلاً لا تمسح ما التُقط في ضغطة سابقة
            $payment->update(['meta_context' => [...$previous, ...$context, 'checkout_tracked' => true]]);

            if ($alreadyTracked) {
                return;
            }

            [$target, $category] = $this->describe($payable);

            $this->send('InitiateCheckout', "checkout_{$target}", $payment->student?->user, $context, $this->paymentData($payment, $target, $category));
        } catch (Throwable $e) {
            Log::warning('Meta checkout tracking failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    public function trackStudentRegistration(User $user, Request $request): void
    {
        $this->send('CompleteRegistration', "registration_{$user->id}", $user, $this->browserContext($request), [
            'content_name' => 'student',
            'status' => true,
        ]);
    }

    /**
     * target بنفس صيغة success_url في PaymentService (booking-12 ...) — صفحة نجاح الدفع
     * تبني منه event_id نفسه لحدث البكسل.
     */
    public function trackPurchase(Payment $payment, Model $payable): void
    {
        [$target, $category] = $this->describe($payable);
        $context = $payment->meta_context ?? [];
        unset($context['checkout_tracked']);

        $this->send('Purchase', "purchase_{$target}", $payment->student?->user, $context, $this->paymentData($payment, $target, $category));
    }

    /** @return array{0: string, 1: string} [target, content_category] */
    private function describe(Model $payable): array
    {
        return match (true) {
            $payable instanceof Booking => ["booking-{$payable->id}", 'booking'],
            $payable instanceof Enrollment => ["enrollment-{$payable->id}", 'enrollment'],
            default => ["support-package-purchase-{$payable->id}", 'support_package_purchase'],
        };
    }

    /** المبلغ من سطر الدفع = ما سيُدفع فعلاً بعد الكوبون */
    private function paymentData(Payment $payment, string $target, string $category): array
    {
        return [
            'value' => (float) $payment->amount,
            'currency' => strtoupper((string) $payment->currency),
            'content_type' => 'product',
            'content_ids' => [$target],
            'content_category' => $category,
            'num_items' => 1,
        ];
    }

    private function send(string $eventName, string $eventId, ?User $user, array $context, array $customData): void
    {
        if (! $this->enabled()) {
            return;
        }

        $eventSourceUrl = $context['event_source_url'] ?? rtrim((string) config('app.frontend_url', config('app.url')), '/');
        unset($context['event_source_url']);

        $payload = array_filter([
            'data' => [[
                'event_name' => $eventName,
                'event_time' => now()->timestamp,
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => $eventSourceUrl,
                'user_data' => [...$this->hashedUserData($user), ...$context],
                'custom_data' => $customData,
            ]],
            'test_event_code' => config('services.meta.test_event_code'),
        ]);

        // مع QUEUE_CONNECTION=sync ينفَّذ الـ Job فوراً هنا، فيُلتقَط فشله بدل أن يصعد للطلب الأصلي
        try {
            SendMetaConversionEventJob::dispatch($payload);
        } catch (Throwable $e) {
            Log::warning('Meta Conversions API event failed', [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Meta تطابق البصمات لا القيم — التطبيع قبل SHA-256 شرط، وإلا لن تتطابق أبداً */
    private function hashedUserData(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $email = strtolower(trim((string) $user->contactEmail()));
        $phone = $this->normalizePhone($user->phone);

        return array_filter([
            'em' => $email !== '' ? [hash('sha256', $email)] : null,
            'ph' => $phone ? [hash('sha256', $phone)] : null,
            'external_id' => [hash('sha256', (string) $user->id)],
        ]);
    }

    /** أرقام فقط مع رمز الدولة بلا + أو 00؛ الرقم المحلي (05xxxxxxxx) يُعتبر إماراتياً */
    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '971'.substr($digits, 1);
        }

        return $digits;
    }
}
