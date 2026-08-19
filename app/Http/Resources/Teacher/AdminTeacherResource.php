<?php

namespace App\Http\Resources\Teacher;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * شكل موحّد لمعلم/مركز من منظور الأدمن (قائمة المعلمين + صفحة التفاصيل).
 * status الحقيقي في القاعدة به 5 حالات؛ الواجهة الأمامية مبنية على 4 فقط
 * (pending/verified/rejected/suspended) لذا active_unverified وpending_verification
 * تُدمَجان معاً كـ"pending" — كلتاهما تعني "لم يوثَّق بعد".
 */
class AdminTeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'avatar' => $this->user?->avatar_path,
            'email' => $this->user?->email,
            'phone' => $this->user?->phone,
            'type' => $this->teacher_type,
            'status' => $this->displayStatus(),
            'rawStatus' => $this->status,
            'canApprove' => $this->status === 'pending_verification',
            'approvalBlockedReason' => $this->approvalBlockedReason(),
            'city' => $this->city,
            'address' => $this->address,
            'website' => $this->website,
            'bio' => $this->bio,
            'qualification' => $this->qualification,
            'experienceYears' => $this->experience_years,
            'ageGroups' => $this->age_groups,
            'teachingMethods' => $this->teaching_methods,
            'displayNameEn' => $this->display_name_en,
            'commercialRegister' => $this->commercial_register,
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar])),
            'curricula' => $this->whenLoaded('curricula', fn () => $this->curricula->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])),
            'languages' => $this->whenLoaded('languages', fn () => $this->languages->map(fn ($l) => ['id' => $l->id, 'name' => $l->name_ar])),
            'submittedAt' => optional($this->created_at)->toDateString(),
            'verifiedAt' => optional($this->verified_at)->toDateString(),
            'rejectionReason' => $this->rejection_reason,
            'suspensionReason' => null,
        ];
    }

    private function displayStatus(): string
    {
        return match ($this->status) {
            'active_unverified', 'pending_verification' => 'pending',
            default => $this->status,
        };
    }

    private function approvalBlockedReason(): ?string
    {
        return match ($this->status) {
            'active_unverified' => 'لم يُكمل المعلم ملفه الشخصي أو يرسل طلب التوثيق بعد، لذلك لا يمكن اعتماده الآن.',
            'rejected' => 'لا يمكن اعتماد الطلب المرفوض مباشرة. يجب أن يُحدّث المعلم ملفه ثم يعيد إرسال طلب التوثيق أولاً.',
            default => null,
        };
    }
}
