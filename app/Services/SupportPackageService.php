<?php

namespace App\Services;

use App\Models\AcademicSupporter;
use App\Models\SupportPackage;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

/**
 * باقات الدعم الطلابي — خاصة بالداعم الأكاديمي (كيان منفصل تماماً عن Teacher).
 * بعكس PackageService: لا مرحلة draft (نموذج واحد يُرسَل مباشرة pending_approval)،
 * ولا هامش منصة إطلاقاً (platform_margin_percent=0 ثابتة، السعر نفسه ثابت حسب
 * الفئة من الإعدادات — لا يتحكم به الداعم ولا الأدمن). راجع تعليق هجرة الجدول.
 */
class SupportPackageService
{
    use LogsAuditEvents;

    public function __construct(private readonly SettingsService $settings) {}

    public function create(AcademicSupporter $supporter, array $data): SupportPackage
    {
        if (! $supporter->isActive()) {
            throw ValidationException::withMessages([
                'supporter_status' => ['لا يمكن إنشاء باقة دعم إلا من حساب نشط'],
            ]);
        }

        $price = $this->priceForTier($data['tier']);

        $supportPackage = SupportPackage::create([
            'academic_supporter_id' => $supporter->id,
            'subject_id' => $data['subject_id'],
            'tier' => $data['tier'],
            'description' => $data['description'],
            'teacher_price' => $price,
            'platform_margin_percent' => 0,
            'student_price' => $price,
            'platform_revenue' => 0,
            'status' => 'pending_approval',
            'submitted_at' => now(),
        ]);

        $this->audit('support_package.submitted', $supportPackage, [], ['status' => 'pending_approval']);

        return $supportPackage->fresh(['subject']);
    }

    private function priceForTier(string $tier): float
    {
        $key = $tier === 'premium' ? 'support_package_premium_price' : 'support_package_basic_price';
        $default = $tier === 'premium' ? 1500 : 1000;

        return (float) $this->settings->get($key, $default);
    }

    public function approve(SupportPackage $supportPackage, User $admin): SupportPackage
    {
        if ($supportPackage->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الموافقة إلا على باقة بانتظار المراجعة'],
            ]);
        }

        $supportPackage->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'rejection_reason' => null,
        ]);

        $this->audit('support_package.approved', $supportPackage, [], ['status' => 'active'], null, $admin->id);

        return $supportPackage;
    }

    public function reject(SupportPackage $supportPackage, User $admin, string $reason): SupportPackage
    {
        if ($supportPackage->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الرفض إلا لباقة بانتظار المراجعة'],
            ]);
        }

        $supportPackage->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $this->audit('support_package.rejected', $supportPackage, [], ['status' => 'rejected'], $reason, $admin->id);

        return $supportPackage;
    }

    /**
     * لا جلسات لهذا النوع فلا فحص جدول/تعارض كـ PackageService::disable — لكن
     * التعطيل يُمنَع إن وُجدت عمليات شراء قائمة (pending_payment أو confirmed)
     * عليها: لا يصح إخفاء باقة طالب دفع ثمنها أو بصدد الدفع لها للتو.
     */
    public function disable(SupportPackage $supportPackage): SupportPackage
    {
        if ($supportPackage->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعطيل الباقة من هذه الحالة'],
            ]);
        }

        if ($supportPackage->purchases()->whereIn('status', ['pending_payment', 'confirmed'])->exists()) {
            throw ValidationException::withMessages([
                'purchases' => ['لا يمكن تعطيل الباقة لوجود عمليات شراء قائمة عليها'],
            ]);
        }

        $supportPackage->update(['status' => 'disabled']);

        $this->audit('support_package.disabled', $supportPackage);

        return $supportPackage;
    }

    public function enable(SupportPackage $supportPackage): SupportPackage
    {
        if ($supportPackage->status !== 'disabled') {
            throw ValidationException::withMessages([
                'status' => ['يمكن إعادة التفعيل فقط لباقة معطّلة'],
            ]);
        }

        $supportPackage->update(['status' => 'active']);

        $this->audit('support_package.enabled', $supportPackage);

        return $supportPackage;
    }
}
