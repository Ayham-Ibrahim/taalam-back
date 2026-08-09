<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * توحيد شكل عنصر المفضلة بصرف النظر عن نوعه (معلم/دورة) — بدل تسريب كل أعمدة
 * النموذج الخام (favorites.favoritable) للطالب. favoritable محمَّل مسبقاً
 * بعلاقاته حسب نوعه في FavoriteController::index عبر morphWith.
 */
class FavoriteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $target = $this->favoritable;

        if ($target instanceof Teacher) {
            return [
                'id' => $this->id,
                'type' => 'teacher',
                'teacher' => [
                    'id' => $target->id,
                    'name' => $target->user?->name,
                    'avatar' => $target->user?->avatar_path,
                    'teacherType' => $target->teacher_type,
                    'city' => $target->city,
                    'rating' => (float) $target->rating_avg,
                    'reviewsCount' => $target->reviews_count,
                    'studentsCount' => $target->students_count,
                    'subjects' => $target->subjects->pluck('name_ar'),
                ],
            ];
        }

        if ($target instanceof Course) {
            return [
                'id' => $this->id,
                'type' => 'course',
                'course' => [
                    'id' => $target->id,
                    'title' => $target->title,
                    // يحتاجها الفرونت للربط بصفحة ملف المركز — الدورة لا تملك صفحة تفصيل مستقلة، تُعرض ضمن ملف المركز فقط
                    'teacherId' => $target->teacher_id,
                    'providerName' => $target->teacher?->user?->name,
                    'providerAvatar' => $target->teacher?->user?->avatar_path,
                    'subject' => $target->subject?->name_ar,
                    'price' => $target->student_price !== null ? (float) $target->student_price : null,
                    'currency' => $target->currency,
                ],
            ];
        }

        // العنصر المفضَّل حُذف — لا نُسقط الصف بل نُعلمه فارغاً كي يستطيع الفرونت إزالته بأمان
        return [
            'id' => $this->id,
            'type' => null,
        ];
    }
}
