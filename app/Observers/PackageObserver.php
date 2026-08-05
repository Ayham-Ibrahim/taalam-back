<?php

namespace App\Observers;

use App\Models\Package;

/**
 * active → full تلقائياً عند اكتمال النصاب، والعكس عند تحرر مقعد (إلغاء حجز مثلاً).
 * لا يُحسب هذا القرار عند القراءة أبداً — العداد enrolled_count محدَّث مسبقاً عبر
 * BookingService (M5)، وهذا الـ Observer فقط يعكس أثره على status.
 */
class PackageObserver
{
    public function saving(Package $package): void
    {
        if ($package->status === 'active' && $package->enrolled_count >= $package->capacity) {
            $package->status = 'full';
        } elseif ($package->status === 'full' && $package->enrolled_count < $package->capacity) {
            $package->status = 'active';
        }
    }
}
