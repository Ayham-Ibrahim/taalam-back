<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Enrollment;
use App\Models\Payout;
use App\Models\PayoutItem;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * يبني فاتورة PDF موحّدة لحجز باقة (Booking) أو تسجيل دورة (Enrollment) — لا
 * يوجد نموذج Invoice منفصل، كل ما تحتاجه الفاتورة موجود أصلاً على الحجز/التسجيل
 * وعملية الدفع الناجحة المرتبطة به.
 *
 * mPDF لا dompdf عمداً: dompdf يعكس ترتيب أحرف أي نص عربي حرفياً (خوارزمية
 * BiDi الخاصة به خاطئة عملياً) بصرف النظر عن dir="rtl"/direction:rtl —
 * "الطالب" تخرج "بلاطلا" فعلياً، أثبتُّ هذا مباشرة عبر اختبار معزول بـ dompdf
 * وحده. mPDF يطبّق خوارزمية Unicode BiDi وتشكيل الحروف العربية (OTL) بشكل
 * صحيح فعلياً عبر autoScriptToLang/autoLangToFont، وهذا سبب الاستبدال الوحيد —
 * كل شيء آخر (القالب، البيانات، الواجهة) بقي كما هو تماماً.
 */
class InvoicePdfService
{
    public function forBooking(Booking $booking): string
    {
        if ($booking->status === 'pending_payment') {
            throw new RuntimeException('لا توجد فاتورة لحجز لم يُدفع بعد');
        }

        $booking->loadMissing(['student.user', 'teacher.user', 'package.subject', 'payments']);

        return $this->render([
            'reference' => $booking->reference,
            'issueDate' => $this->paidAt($booking),
            'studentName' => $booking->student?->user?->name,
            'studentEmail' => $booking->student?->user?->email,
            'teacherName' => $booking->teacher?->user?->name,
            'itemTitle' => $booking->package?->title,
            'subject' => $booking->package?->subject?->name_ar,
            'sessionsCount' => $booking->sessions_total,
            'amount' => $booking->amount_paid,
            'currency' => $booking->currency,
            'paymentMethod' => $booking->is_manual ? 'دفع يدوي' : 'Stripe',
            'statusLabel' => $this->statusLabel($booking->status),
        ]);
    }

    public function forEnrollment(Enrollment $enrollment): string
    {
        if ($enrollment->status === 'pending_payment') {
            throw new RuntimeException('لا توجد فاتورة لتسجيل لم يُدفع بعد');
        }

        $enrollment->loadMissing(['student.user', 'teacher.user', 'course.subject', 'payments']);

        return $this->render([
            'reference' => $enrollment->reference,
            'issueDate' => $this->paidAt($enrollment),
            'studentName' => $enrollment->student?->user?->name,
            'studentEmail' => $enrollment->student?->user?->email,
            'teacherName' => $enrollment->teacher?->user?->name,
            'itemTitle' => $enrollment->course?->title,
            'subject' => $enrollment->course?->subject?->name_ar,
            'sessionsCount' => $enrollment->course?->total_sessions,
            'amount' => $enrollment->amount_paid,
            'currency' => $enrollment->currency,
            'paymentMethod' => $enrollment->is_manual ? 'دفع يدوي' : 'Stripe',
            'statusLabel' => $this->statusLabel($enrollment->status),
        ]);
    }

    /**
     * كشف مستحقات (لا فاتورة طالب) — لا معنى له قبل الاعتماد (pending قابلة
     * للتعديل بالكامل، لا رقم رسمي يُصدَر عنها بعد). قالب منفصل عن invoices.pdf
     * (بند واحد ثابت) لأن كشف المستحقات يحتاج سطراً لكل جلسة ضمن الفترة.
     */
    public function forPayout(Payout $payout): string
    {
        if (! in_array($payout->status, ['approved', 'paid'], true)) {
            throw new RuntimeException('لا يتوفر كشف مستحقات قبل اعتمادها');
        }

        $payout->loadMissing(['teacher.user', 'items.session.course', 'items.session.booking.package']);

        $items = $payout->items->map(function (PayoutItem $item) {
            $session = $item->session;

            return [
                'date' => $session?->scheduled_at?->format('Y-m-d') ?? '—',
                'title' => $session?->course?->title ?? $session?->booking?->package?->title ?? '—',
                'amount' => (float) $item->amount,
            ];
        });

        return $this->render([
            'payoutId' => $payout->id,
            'teacherName' => $payout->teacher?->user?->name,
            'periodStart' => $payout->period_start->format('Y-m-d'),
            'periodEnd' => $payout->period_end->format('Y-m-d'),
            'issueDate' => ($payout->paid_at ?? $payout->approved_at ?? $payout->created_at)->format('Y-m-d'),
            'sessionsCount' => $payout->sessions_count,
            'items' => $items,
            'grossAmount' => (float) $payout->gross_amount,
            'deductions' => (float) $payout->deductions,
            'netAmount' => (float) $payout->net_amount,
            'currency' => $payout->currency,
            'statusLabel' => $this->payoutStatusLabel($payout->status),
            'transferReference' => $payout->transfer_reference,
        ], 'invoices.payout-pdf');
    }

    private function payoutStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'معتمدة',
            'paid' => 'مدفوعة',
            default => $status,
        };
    }

    private function paidAt(Booking|Enrollment $record): string
    {
        $paidAt = $record->payments->firstWhere('status', 'paid')?->paid_at ?? $record->confirmed_at ?? $record->created_at;

        return $paidAt->format('Y-m-d');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed', 'in_progress', 'completed' => 'مدفوعة',
            'cancelled', 'withdrawn' => 'ملغاة',
            default => $status,
        };
    }

    private function render(array $data, string $view = 'invoices.pdf'): string
    {
        $data['logoDataUri'] = $this->logoDataUri();

        $mpdf = new Mpdf([
            'format' => 'A4',
            'tempDir' => storage_path('framework/mpdf'),
            // يفحصان محتوى كل نص فعلياً (لا الإعداد الثابت) ويبدّلان الخط
            // والتشكيل تلقائياً حسب السكربت الفعلي لكل جزء — ضروريان معاً هنا
            // تحديداً لأن الفاتورة تخلط عربي (تسميات) بلاتيني (أسماء/تواريخ/
            // Stripe/USD) في نفس السطر أحياناً.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * محرك PDF لا يصل بشكل موثوق لملفات عبر مسار نسبي/asset() حسب إعدادات
     * chroot الخاصة به — تضمين الشعار كـ data URI يتجاوز ذلك تماماً.
     */
    private function logoDataUri(): string
    {
        $path = public_path('images/logo-email.png');

        if (! is_file($path)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}
