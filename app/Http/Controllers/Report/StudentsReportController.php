<?php

namespace App\Http\Controllers\Report;

use App\Exports\StudentsReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\StudentsReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class StudentsReportController extends Controller
{
    public function __construct(private readonly StudentsReportService $reportService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $request->validate(['week_start' => ['nullable', 'date']]);

        return $this->success($this->reportService->getReport(
            $request->filled('week_start') ? Carbon::parse($request->string('week_start')) : null,
        ));
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $request->validate([
            'week_start' => ['nullable', 'date'],
            'sheet' => ['nullable', 'in:'.implode(',', StudentsReportExport::SINGLE_SHEETS)],
        ]);

        $sheet = $request->input('sheet');

        return Excel::download(
            new StudentsReportExport($this->reportService, $request->input('week_start'), $sheet),
            $sheet ? "students-report-{$sheet}.xlsx" : 'students-report.xlsx',
        );
    }
}
