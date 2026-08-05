<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

class CourseApprovalService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly SettingsService $settings,
    ) {}

    public function approve(Course $course, User $admin, float $marginPercent): Course
    {
        if ($course->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الموافقة إلا على دورة بانتظار المراجعة'],
            ]);
        }

        $min = (float) $this->settings->get('min_platform_margin_percent', 0);
        $max = (float) $this->settings->get('max_platform_margin_percent', 1000);

        if ($marginPercent < $min || $marginPercent > $max) {
            throw ValidationException::withMessages([
                'platform_margin_percent' => ["نسبة المنصة يجب أن تكون بين {$min}% و {$max}%"],
            ]);
        }

        // pricing_mode=total: provider_price سعر الدورة كاملة (units=1). hourly: سعر الساعة × total_hours
        $units = $course->pricing_mode === 'hourly' ? (float) $course->total_hours : 1;
        $computed = $this->pricing->calculateStudentPrice((float) $course->provider_price, $marginPercent, $units);

        $old = $course->only(['status', 'platform_margin_percent', 'student_price', 'platform_revenue']);

        $course->update([
            'platform_margin_percent' => $marginPercent,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'rejection_reason' => null,
        ]);

        $this->audit('course.approved', $course, $old, [
            'status' => 'active',
            'platform_margin_percent' => $marginPercent,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
        ]);

        return $course;
    }

    public function reject(Course $course, User $admin, string $reason): Course
    {
        if ($course->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الرفض إلا لدورة بانتظار المراجعة'],
            ]);
        }

        $old = $course->only(['status']);

        $course->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $this->audit('course.rejected', $course, $old, ['status' => 'rejected'], $reason);

        return $course;
    }
}
