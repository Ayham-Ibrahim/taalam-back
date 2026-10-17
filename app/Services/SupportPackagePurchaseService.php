<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Student;
use App\Models\SupportPackage;
use App\Models\SupportPackagePurchase;
use App\Notifications\SupportPackagePurchaseConfirmed;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * شراء باقة دعم أكاديمي — نظير BookingService/EnrollmentService مبسّط (لا جلسات/BBB،
 * فقط سطر شراء + Payment معلّق، يؤكَّده PaymentService عبر webhook Stripe).
 * التحقق من education_type='university' هنا أيضاً (لا فقط إخفاء التاب بالواجهة).
 */
class SupportPackagePurchaseService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CouponService $coupons,
    ) {}

    public function initiate(Student $student, SupportPackage $supportPackage, ?string $couponCode = null): SupportPackagePurchase
    {
        $this->assertUniversityStudent($student);
        $this->assertPackagePurchasable($supportPackage);
        $this->assertNoOpenPurchase($student, $supportPackage);

        return DB::transaction(function () use ($student, $supportPackage, $couponCode) {
            $amount = $supportPackage->student_price;
            $couponId = null;
            $discountAmount = null;

            if ($couponCode) {
                $result = $this->coupons->validateAndReserveForSupportPackage($couponCode, $supportPackage);
                $amount = $result['amountPaid'];
                $couponId = $result['coupon']->id;
                $discountAmount = $result['discountAmount'];
            }

            $purchase = SupportPackagePurchase::create([
                'student_id' => $student->id,
                'academic_supporter_id' => $supportPackage->academic_supporter_id,
                'support_package_id' => $supportPackage->id,
                'coupon_id' => $couponId,
                'amount' => $amount,
                'discount_amount' => $discountAmount,
                'currency' => $supportPackage->currency,
                'status' => 'pending_payment',
                'hold_expires_at' => now()->addMinutes((int) $this->settings->get('booking_payment_hold_minutes', 15)),
            ]);

            $this->createPendingPayment($purchase);

            return $purchase->fresh();
        });
    }

    /**
     * يُستدعى بعد نجاح الدفع (من PaymentService عبر webhook Stripe). عملية idempotent —
     * استدعاؤها لشراء مؤكَّد مسبقاً لا يُحدث شيئاً.
     */
    public function confirmPurchase(SupportPackagePurchase $purchase): SupportPackagePurchase
    {
        if ($purchase->status !== 'pending_payment') {
            return $purchase;
        }

        $purchase->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'hold_expires_at' => null,
        ]);

        $purchase->loadMissing(['academicSupporter.user', 'student.user', 'supportPackage']);
        $purchase->academicSupporter->user->notify(new SupportPackagePurchaseConfirmed($purchase));

        return $purchase;
    }

    private function assertUniversityStudent(Student $student): void
    {
        if ($student->education_type !== 'university') {
            throw ValidationException::withMessages([
                'student' => ['هذا القسم مخصص للطلاب الجامعيين فقط'],
            ]);
        }
    }

    private function assertPackagePurchasable(SupportPackage $supportPackage): void
    {
        if ($supportPackage->status !== 'active') {
            throw ValidationException::withMessages([
                'support_package' => ['هذه الباقة غير متاحة للشراء حالياً'],
            ]);
        }
    }

    /**
     * "قائم لم ينتهِ" = شراء لنفس الباقة بحالة pending_payment أو confirmed — لا يصح
     * شراء نفس الباقة مرتين طالما الأولى لم تُلغَ/تنتهِ.
     */
    private function assertNoOpenPurchase(Student $student, SupportPackage $supportPackage): void
    {
        $hasOpenPurchase = SupportPackagePurchase::where('student_id', $student->id)
            ->where('support_package_id', $supportPackage->id)
            ->whereIn('status', ['pending_payment', 'confirmed'])
            ->exists();

        if ($hasOpenPurchase) {
            throw ValidationException::withMessages([
                'support_package' => ['لديك بالفعل اشتراك قائم في هذه الباقة'],
            ]);
        }
    }

    private function createPendingPayment(SupportPackagePurchase $purchase): Payment
    {
        return Payment::create([
            'support_package_purchase_id' => $purchase->id,
            'student_id' => $purchase->student_id,
            'amount' => $purchase->amount,
            'provider_amount' => 0,
            'platform_amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'method' => 'stripe',
            'status' => 'pending',
        ]);
    }
}
