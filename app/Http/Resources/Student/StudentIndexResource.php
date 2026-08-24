<?php

namespace App\Http\Resources\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * صف قائمة طلاب — يُستخدم في مكانين مختلفين تماماً عن نفس نقطة /students:
 * منتقي الحجز اليدوي لدى الأدمن (بحث سريع، لا يحتاج غير الاسم/الإيميل/الهاتف)،
 * وصفحة "إدارة الطلاب" الكاملة لدى الأدمن — إضافة حقول هنا آمنة تماماً
 * للمستهلك الأول (يتجاهل ما لا يحتاجه)، ولا داعي لمضاعفة الـ Resource.
 */
class StudentIndexResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'avatar' => $this->user->avatar_path,
            'is_active' => $this->user->is_active,
            'education_type' => $this->education_type,
            'imported' => $this->imported,
            'created_at' => $this->user->created_at,
        ];
    }
}
