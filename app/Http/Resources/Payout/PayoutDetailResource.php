<?php

namespace App\Http\Resources\Payout;

use App\Models\PayoutItem;
use Illuminate\Http\Request;

/**
 * تفاصيل كشف مستحقات (GET payouts/{payout}): حقول القائمة نفسها + الإجماليات وبنود الكشف —
 * لكل بند جلسته (التاريخ، الدورة/الباقة، تعويضية؟) ونوعه (جلسة/تسوية تسجيل متأخر). نتيجة
 * الحضور ونسبة المستحق شأن داخلي للمالية فقط (نفس ClassSession::$hidden)، لا تظهر للمعلم.
 */
class PayoutDetailResource extends PayoutResource
{
    public function toArray(Request $request): array
    {
        $isFinance = $request->user()?->isFinance() ?? false;

        return [
            ...parent::toArray($request),
            'grossAmount' => (float) $this->gross_amount,
            'deductions' => (float) $this->deductions,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'createdAt' => optional($this->created_at)->toDateString(),
            'approvedBy' => $this->approver?->name,
            'items' => $this->items
                ->sortBy(fn (PayoutItem $item) => [$item->session?->scheduled_at?->getTimestamp() ?? 0, $item->id])
                ->values()
                ->map(function (PayoutItem $item) use ($isFinance) {
                    $session = $item->session;
                    $package = $session?->booking?->package;

                    return [
                        'id' => $item->id,
                        'type' => $item->type,
                        'amount' => (float) $item->amount,
                        'sessionId' => $item->class_session_id,
                        'scheduledAt' => $session?->scheduled_at?->toIso8601String(),
                        'title' => $session?->course?->title ?? $package?->title,
                        'kind' => $session?->course_id ? 'course' : $package?->session_format,
                        'isMakeup' => (bool) $session?->is_makeup,
                        'attendanceOutcome' => $this->when($isFinance, fn () => $session?->attendance_outcome),
                        'payoutPercent' => $this->when($isFinance, fn () => $session?->teacher_payout_percent !== null
                            ? (float) $session->teacher_payout_percent
                            : null),
                        'compensationAmount' => $this->when($isFinance, fn () => $session?->teacher_compensation_amount !== null
                            ? (float) $session->teacher_compensation_amount
                            : null),
                    ];
                })
                ->all(),
        ];
    }
}
