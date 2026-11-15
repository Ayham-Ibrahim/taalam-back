<?php

namespace App\Http\Controllers\Report;

use App\Exports\PlatformNetProfitExport;
use App\Http\Controllers\Controller;
use App\Services\PlatformNetProfitService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/** صافي أرباح المنصة — للأدمن والمحاسب (view-finance-reports). الافتراضي: الشهر الحالي. */
class PlatformNetProfitReportController extends Controller
{
    public function __construct(private readonly PlatformNetProfitService $service) {}

    public function index(Request $request)
    {
        $this->authorize('view-finance-reports');

        [$from, $to] = $this->period($request);

        return $this->success($this->service->getReport($from, $to));
    }

    public function export(Request $request)
    {
        $this->authorize('view-finance-reports');

        [$from, $to] = $this->period($request);

        return Excel::download(
            new PlatformNetProfitExport($this->service->getReport($from, $to)),
            "platform-net-profit-{$from->toDateString()}-to-{$to->toDateString()}.xlsx",
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')) : now();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['to' => ['تاريخ النهاية يجب أن يكون بعد تاريخ البداية']]);
        }

        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;
        if ($months > PlatformNetProfitService::MAX_MONTHS) {
            throw ValidationException::withMessages([
                'to' => ['أقصى مدة للتقرير '.PlatformNetProfitService::MAX_MONTHS.' شهراً'],
            ]);
        }

        return [$from, $to];
    }
}
