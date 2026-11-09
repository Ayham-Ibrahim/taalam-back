<?php

namespace App\Http\Controllers\Report;

use App\Exports\AcademicSupportFinanceExport;
use App\Http\Controllers\Controller;
use App\Services\AcademicSupportFinanceReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class AcademicSupportFinanceReportController extends Controller
{
    public function __construct(private readonly AcademicSupportFinanceReportService $reportService) {}

    public function index(Request $request)
    {
        $this->authorize('view-finance-reports');

        $this->validateFilters($request);

        $report = $this->reportService->getReport(
            $request->filled('from') ? Carbon::parse($request->string('from')) : null,
            $request->filled('to') ? Carbon::parse($request->string('to')) : null,
            $request->integer('supporter_id') ?: null,
        );

        return $this->success($report);
    }

    public function export(Request $request)
    {
        $this->authorize('view-finance-reports');

        $this->validateFilters($request);
        $request->validate([
            'sheet' => ['nullable', 'in:'.implode(',', AcademicSupportFinanceExport::SINGLE_SHEETS)],
        ]);

        $sheet = $request->input('sheet');

        return Excel::download(
            new AcademicSupportFinanceExport($this->reportService, $request->only(['from', 'to', 'supporter_id']), $sheet),
            $sheet ? "academic-support-{$sheet}.xlsx" : 'academic-support-finance-report.xlsx',
        );
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'supporter_id' => ['nullable', 'integer'],
        ]);
    }
}
