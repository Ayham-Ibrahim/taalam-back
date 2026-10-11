<?php

namespace App\Services;

use App\Models\Package;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

class PackageApprovalService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly SettingsService $settings,
    ) {}

    public function approve(Package $package, User $admin, float $marginPercent): Package
    {
        if ($package->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الموافقة إلا على باقة بانتظار المراجعة'],
            ]);
        }

        $min = (float) $this->settings->get('min_platform_margin_percent', 0);
        $max = (float) $this->settings->get('max_platform_margin_percent', 1000);

        if ($marginPercent < $min || $marginPercent > $max) {
            throw ValidationException::withMessages([
                'platform_margin_percent' => ["نسبة المنصة يجب أن تكون بين {$min}% و {$max}%"],
            ]);
        }

        // teacher_price دائماً سعر الساعة الواحدة (كل جلسة = ساعة واحدة) — السعر الكلي × sessions_count
        $computed = $this->pricing->calculateStudentPrice((float) $package->teacher_price, $marginPercent, $package->sessions_count);

        $old = $package->only(['status', 'platform_margin_percent', 'student_price', 'platform_revenue']);

        $package->update([
            'platform_margin_percent' => $marginPercent,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'rejection_reason' => null,
        ]);

        $this->audit('package.approved', $package, $old, [
            'status' => 'active',
            'platform_margin_percent' => $marginPercent,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
        ]);

        return $package;
    }

    public function reject(Package $package, User $admin, string $reason): Package
    {
        if ($package->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الرفض إلا لباقة بانتظار المراجعة'],
            ]);
        }

        $old = $package->only(['status']);

        $package->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $this->audit('package.rejected', $package, $old, ['status' => 'rejected'], $reason);

        return $package;
    }
}
