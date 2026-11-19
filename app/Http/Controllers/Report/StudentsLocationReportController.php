<?php

namespace App\Http\Controllers\Report;

use App\Exports\StudentsLocationReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\StudentsLocationReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/** تقرير التسويق: الطلاب حسب الموقع × المنهج، والصف والمادة — نفس صلاحية تقرير الطلاب (StudentPolicy::viewAny) */
class StudentsLocationReportController extends Controller
{
    public function __construct(private readonly StudentsLocationReportService $reportService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        return $this->success($this->reportService->getReport($this->filters($request)));
    }

    public function students(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->paginate($this->reportService->students($this->filters($request), $request->integer('per_page', 25)));
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $filters = $this->filters($request);

        return Excel::download(new StudentsLocationReportExport($this->reportService, $filters), 'students-location-report.xlsx');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'education_type' => ['nullable', Rule::in(['school', 'university', 'training'])],
            // رقم صف (1–12) أو تصنيف من لا صف له
            'grade' => ['nullable', Rule::in([...range(1, 12), ...StudentsLocationReportService::GRADE_BUCKETS])],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }
}
