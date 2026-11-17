<?php

namespace App\Http\Resources\Package;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * الطالب لا يرى سعر المعلم إطلاقاً — فقط السعر النهائي (§2 في القرارات المعمارية).
 */
class StudentPackageResource extends JsonResource
{
    /** وضع الطالب المشاهد مع الباقة (PackageController::viewerContext) — null = لا يُرسَل شيء */
    private ?array $viewer = null;

    public function forViewer(?array $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $booking = $this->viewer['booking'] ?? null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject?->name_ar),
            'session_format' => $this->session_format,
            'is_trial' => (bool) $this->is_trial,
            // campaign: باقة حملة مخفضة — لحسابات الحملة فقط، مبلغها للمنصة والمعلم براتب ثابت
            'audience' => $this->audience ?? 'public',
            // منطقة ساعات schedules (start_time/end_time) — لازمة لعرض نوافذ باقة الحملة بمنطقة المشاهد
            'schedule_timezone' => $this->schedule_timezone,
            'capacity' => $this->capacity,
            'enrolled_count' => $this->enrolled_count,
            'sessions_count' => $this->sessions_count,
            'student_price' => $this->student_price !== null ? (float) $this->student_price : null,
            'currency' => $this->currency,
            'discount_percent' => $this->discount_percent !== null ? (float) $this->discount_percent : null,
            'status' => $this->status,
            'curricula' => $this->whenLoaded('curricula'),
            'stages' => $this->whenLoaded('stages'),
            'grades' => $this->grades,
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($s) => [
                'id' => $s->id, 'date' => $s->date?->toDateString(), 'day_of_week' => $s->day_of_week, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
                // اللحظة الفعلية (UTC) — date/start_time بمنطقة المعلم، لا منطقة من يعرضها
                'starts_at' => $s->startsAt($this->schedule_timezone)?->toIso8601String(),
            ])),
            // حجز الطالب القائم على هذه الباقة (Booking::open) — null إن لا حجز قائم
            'my_booking' => $this->when($this->viewer !== null, fn () => $booking ? [
                'id' => $booking->id,
                'status' => $booking->status,
                'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
                'requested_at' => $this->viewer['requested_at']?->toIso8601String(),
                'sessions_remaining' => $booking->sessions_remaining,
            ] : null),
            'trial_used' => $this->when($this->viewer !== null, fn () => $this->viewer['trial_used']),
            'campaign_right_used' => $this->when($this->viewer !== null, fn () => $this->viewer['campaign_right_used']),
        ];
    }
}
