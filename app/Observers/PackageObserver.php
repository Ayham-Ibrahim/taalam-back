<?php

namespace App\Observers;

use App\Models\Package;

/**
 * active → full تلقائياً عند اكتمال النصاب، والعكس عند تحرر مقعد (إلغاء حجز مثلاً).
 * لا يُحسب هذا القرار عند القراءة أبداً — العداد enrolled_count محدَّث مسبقاً عبر
 * BookingService (M5)، وهذا الـ Observer فقط يعكس أثره على status.
 *
 * الباقة الفردية مستثناة عمداً: capacity=1 فيها يعني "طالب واحد لكل جلسة"، لا
 * "طالب واحد للباقة كلها للأبد" — القيد الحقيقي الوحيد على حجز جديد (من أي طالب)
 * هو تعارض جدول المعلم الفعلي (ScheduleConflictService)، لا نصاب مشترك كالمجموعة.
 * بلا هذا الاستثناء، أول حجز فردي مؤكَّد يُحوِّل status لـ full فيُخفي الباقة عن
 * كل طالب آخر رغم وجود أوقات فارغة أخرى لدى المعلم تماماً.
 */
class PackageObserver
{
    public function saving(Package $package): void
    {
        if ($package->session_format === 'individual') {
            return;
        }

        if ($package->status === 'active' && $package->enrolled_count >= $package->capacity) {
            $package->status = 'full';
        } elseif ($package->status === 'full' && $package->enrolled_count < $package->capacity) {
            $package->status = 'active';
        }
    }
}
