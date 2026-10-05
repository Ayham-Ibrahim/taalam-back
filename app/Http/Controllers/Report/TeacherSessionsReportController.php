<?php

namespace App\Http\Controllers\Report;

use App\Exports\TeacherSessionsReportExport;
use App\Http\Controllers\Controller;
use App\Services\TeacherSessionsReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class TeacherSessionsReportController extends Controller
{
    public function __construct(private readonly TeacherSessionsReportService $reportService) {}

    public function index(Request $request)
    {
        $this->authorize('view-finance-reports');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'teacher_id' => ['nullable', 'integer'],
        ]);

        $report = $this->reportService->getReport(
            $request->filled('from') ? Carbon::parse($request->string('from')) : null,
            $request->filled('to') ? Carbon::parse($request->string('to')) : null,
            $request->integer('teacher_id') ?: null,
        );

        return $this->success($report);
    }

    public function export(Request $request)
    {
        $this->authorize('view-finance-reports');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'teacher_id' => ['nullable', 'integer'],
        ]);

        return Excel::download(
            new TeacherSessionsReportExport($this->reportService, $request->only(['from', 'to', 'teacher_id'])),
            'teacher-sessions-report.xlsx',
        );
    }
}
