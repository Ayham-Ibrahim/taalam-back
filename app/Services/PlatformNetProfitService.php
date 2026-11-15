<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * صافي أرباح المنصة لفترة = أرباحها من حصص المعلمين (باقات ودورات) + أرباحها من الدعم
 * الأكاديمي − رواتب الموظفين. لا حساب جديد: كل رقم من تقريره المعتمد نفسه، فيطابق ما يظهر فيه:
 * - حصص المعلمين: TeacherSessionsReportService (platformRevenue، حسب تاريخ الجلسة المنعقدة).
 * - الدعم الأكاديمي: AcademicSupportFinanceReportService (platformAmount، حسب تاريخ الدفع).
 * - الرواتب: EmployeePayrollService::report (netTotal بالدرهم) — كشوف شهرية، فيُحتسب راتب كل
 *   شهر تلمسه الفترة كاملاً.
 * مستحقات المعلمين والداعمين لا تُطرح: التقريران يعطيان حصة المنصة بعد فصلها أصلاً.
 */
class PlatformNetProfitService
{
    /** حد معقول لعدد الأشهر في تقرير واحد (التفصيل الشهري يحسب كل شهر على حدة) */
    public const MAX_MONTHS = 24;

    public function __construct(
        private readonly TeacherSessionsReportService $sessionsReport,
        private readonly AcademicSupportFinanceReportService $supportReport,
        private readonly EmployeePayrollService $payroll,
    ) {}

    public function getReport(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $months = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $segmentStart = $cursor->copy()->max($from);
            $segmentEnd = $cursor->copy()->endOfMonth()->min($to);
            $months[] = $this->monthRow($cursor->format('Y-m'), $segmentStart, $segmentEnd);
            $cursor->addMonth();
        }

        $sum = fn (string $key) => round(array_sum(array_column($months, $key)), 2);

        return [
            'currency' => Employee::BASE_CURRENCY,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'sessionsProfit' => $sum('sessionsProfit'),
                'supportProfit' => $sum('supportProfit'),
                'totalProfit' => $sum('totalProfit'),
                'salaries' => $sum('salaries'),
                'netProfit' => $sum('netProfit'),
                'sessionsCount' => (int) array_sum(array_column($months, 'sessionsCount')),
                'supportPurchasesCount' => (int) array_sum(array_column($months, 'supportPurchasesCount')),
            ],
            'months' => $months,
        ];
    }

    private function monthRow(string $month, Carbon $from, Carbon $to): array
    {
        $sessions = $this->sessionsReport->getReport($from, $to)['totals'];
        $support = $this->supportReport->getReport($from, $to)['totals'];
        $salaries = $this->payroll->report($month, $month, null)['summary']['netTotal'];

        $sessionsProfit = round((float) $sessions['platformRevenue'], 2);
        $supportProfit = round((float) $support['platformAmount'], 2);
        $totalProfit = round($sessionsProfit + $supportProfit, 2);

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sessionsCount' => (int) $sessions['sessionsCount'],
            'supportPurchasesCount' => (int) $support['purchasesCount'],
            'sessionsProfit' => $sessionsProfit,
            'supportProfit' => $supportProfit,
            'totalProfit' => $totalProfit,
            'salaries' => round((float) $salaries, 2),
            'netProfit' => round($totalProfit - (float) $salaries, 2),
        ];
    }
}
