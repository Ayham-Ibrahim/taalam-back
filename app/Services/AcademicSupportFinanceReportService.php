<?php

namespace App\Services;

use App\Models\AcademicSupporter;
use App\Models\SupportPackagePurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * المحاسبة المالية للدعم الأكاديمي (لوحة المالية، للأدمن والمحاسب) — من اشتراكات باقات
 * الدعم المؤكَّدة (المدفوعة) فقط، مُؤرَّخة بتاريخ تأكيد الدفع (confirmed_at):
 *
 * - مستحق الداعم = teacher_price للباقة (= student_price × supporter_profit_percent) — التزام
 *   المنصة تجاهه عن كل اشتراك. ثابت لا يتأثر بالكوبون: الخصم تتحمّله المنصة كباقي المنصة
 *   (CouponService::validateAndReserve يُبقي مستحق المعلم كما هو).
 * - عائد المنصة = ما دفعه الطالب فعلاً (amount، بعد الخصم) − مستحق الداعم — قد يكون سالباً
 *   لاشتراك بكوبون خصمه أكبر من هامش المنصة (CouponService لا يحدّ خصم باقات الدعم بالهامش).
 *
 * أسعار الباقة تُقرأ من الباقة نفسها لا من الاشتراك: SupportPackageService::update يرفض تعديل
 * باقة لها اشتراك مؤكَّد أو معلّق، فتسعيرها ثابت منذ أول اشتراك مدفوع فيها. الباقة والداعم
 * يُقرآن مع المحذوفين ناعماً — حذفهما لا يُسقط ما دُفع عنهما من الحسابات.
 */
class AcademicSupportFinanceReportService
{
    public function getReport(?Carbon $from, ?Carbon $to, ?int $supporterId = null): array
    {
        $purchases = SupportPackagePurchase::query()
            ->where('status', 'confirmed')
            ->when($from, fn ($q) => $q->where('confirmed_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('confirmed_at', '<=', $to->copy()->endOfDay()))
            ->when($supporterId, fn ($q) => $q->where('academic_supporter_id', $supporterId))
            ->with([
                'student.user:id,name',
                'academicSupporter' => fn ($q) => $q->withTrashed()->with('user:id,name'),
                'supportPackage' => fn ($q) => $q->withTrashed()->with('subjects:id,name_ar'),
                'coupon:id,code',
            ])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get();

        $rows = $purchases->map(fn (SupportPackagePurchase $purchase) => $this->purchaseRow($purchase));

        return [
            'totals' => $this->totals($rows),
            'supporters' => $this->supporterRows($rows),
            'packages' => $this->packageRows($rows),
            'purchases' => $rows->values()->all(),
            'supporterOptions' => $this->supporterOptions(),
        ];
    }

    private function purchaseRow(SupportPackagePurchase $purchase): array
    {
        $package = $purchase->supportPackage;
        $listPrice = (float) ($package?->student_price ?? $purchase->amount);
        $supporterAmount = (float) ($package?->teacher_price ?? 0);
        $amountPaid = (float) $purchase->amount;
        $supporterPercent = $this->supporterPercent($package?->supporter_profit_percent, $supporterAmount, $listPrice);

        return [
            'purchaseId' => $purchase->id,
            'confirmedAt' => $purchase->confirmed_at?->format('Y-m-d H:i'),
            'studentName' => $purchase->student?->user?->name,
            'supporterId' => $purchase->academic_supporter_id,
            'supporterName' => $purchase->academicSupporter?->user?->name,
            'packageId' => $purchase->support_package_id,
            'packageTitle' => $package?->title,
            'tier' => $package?->tier,
            'subjects' => $package?->subjects->pluck('name_ar')->values()->all() ?? [],
            'listPrice' => round($listPrice, 2),
            'discountAmount' => round((float) ($purchase->discount_amount ?? 0), 2),
            'couponCode' => $purchase->coupon?->code,
            'amountPaid' => round($amountPaid, 2),
            'supporterPercent' => $supporterPercent,
            'platformPercent' => round(100 - $supporterPercent, 2),
            'supporterAmount' => round($supporterAmount, 2),
            'platformAmount' => round($amountPaid - $supporterAmount, 2),
            'currency' => $purchase->currency,
        ];
    }

    /** باقات قديمة قبل إضافة supporter_profit_percent: تُستنتج النسبة من السعرين (كانت 100% للداعم) */
    private function supporterPercent(mixed $storedPercent, float $supporterAmount, float $listPrice): float
    {
        if ($storedPercent !== null) {
            return round((float) $storedPercent, 2);
        }

        return $listPrice > 0 ? round($supporterAmount / $listPrice * 100, 2) : 0.0;
    }

    private function totals(Collection $rows): array
    {
        return [
            'purchasesCount' => $rows->count(),
            'supportersCount' => $rows->pluck('supporterId')->unique()->count(),
            'studentsPaid' => round((float) $rows->sum('amountPaid'), 2),
            'discountAmount' => round((float) $rows->sum('discountAmount'), 2),
            'supporterAmount' => round((float) $rows->sum('supporterAmount'), 2),
            'platformAmount' => round((float) $rows->sum('platformAmount'), 2),
        ];
    }

    /** صف لكل داعم: إجمالي ما دفعه طلابه، ومستحقه (التزام المنصة تجاهه)، وعائد المنصة منه */
    private function supporterRows(Collection $rows): array
    {
        return $rows->groupBy('supporterId')
            ->map(fn (Collection $group) => [
                'supporterId' => $group->first()['supporterId'],
                'supporterName' => $group->first()['supporterName'],
                'packagesCount' => $group->pluck('packageId')->unique()->count(),
                'subjects' => $group->pluck('subjects')->flatten()->unique()->values()->all(),
                'purchasesCount' => $group->count(),
                'studentsPaid' => round((float) $group->sum('amountPaid'), 2),
                'discountAmount' => round((float) $group->sum('discountAmount'), 2),
                'supporterAmount' => round((float) $group->sum('supporterAmount'), 2),
                'platformAmount' => round((float) $group->sum('platformAmount'), 2),
            ])
            ->sortByDesc('supporterAmount')
            ->values()
            ->all();
    }

    /** صف لكل باقة بيعت: الداعم المسؤول عن تقديمها، موادها، ونسبتا الداعم والمنصة من سعرها */
    private function packageRows(Collection $rows): array
    {
        return $rows->groupBy('packageId')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'packageId' => $first['packageId'],
                    'packageTitle' => $first['packageTitle'],
                    'tier' => $first['tier'],
                    'supporterId' => $first['supporterId'],
                    'supporterName' => $first['supporterName'],
                    'subjects' => $first['subjects'],
                    'listPrice' => $first['listPrice'],
                    'supporterPercent' => $first['supporterPercent'],
                    'platformPercent' => $first['platformPercent'],
                    'purchasesCount' => $group->count(),
                    'studentsPaid' => round((float) $group->sum('amountPaid'), 2),
                    'discountAmount' => round((float) $group->sum('discountAmount'), 2),
                    'supporterAmount' => round((float) $group->sum('supporterAmount'), 2),
                    'platformAmount' => round((float) $group->sum('platformAmount'), 2),
                ];
            })
            ->sortBy([['supporterName', 'asc'], ['packageTitle', 'asc']])
            ->values()
            ->all();
    }

    /** خيارات فلتر الداعم — كل من له اشتراك مدفوع ولو خارج الفترة المختارة */
    private function supporterOptions(): array
    {
        return AcademicSupporter::withTrashed()
            ->whereHas('purchases', fn ($q) => $q->where('status', 'confirmed'))
            ->with('user:id,name')
            ->get(['id', 'user_id'])
            ->map(fn (AcademicSupporter $supporter) => ['id' => $supporter->id, 'name' => $supporter->user?->name])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
