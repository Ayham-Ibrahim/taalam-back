<?php

namespace App\Http\Controllers\Course;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\ApproveCourseRequest;
use App\Http\Requests\Course\CreateCourseRequest;
use App\Http\Requests\Course\RejectCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\Course\AdminCourseResource;
use App\Http\Resources\Course\StudentCourseResource;
use App\Http\Resources\Course\TeacherCourseResource;
use App\Models\Course;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseApprovalService;
use App\Services\CourseService;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(
        private readonly CourseService $courseService,
        private readonly CourseApprovalService $approvalService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Course::class);

        $user = $request->user();

        $courses = Course::query()
            ->select([
                'id', 'teacher_id', 'title', 'course_field_id', 'subject_id', 'level',
                'delivery_mode', 'start_date', 'end_date', 'total_sessions', 'max_seats',
                'enrolled_count', 'provider_price', 'platform_margin_percent', 'student_price',
                'platform_revenue', 'currency', 'status', 'submitted_at', 'approved_at',
                'approved_by', 'rejection_reason', 'created_at',
            ])
            ->with(['teacher:id,user_id,teacher_type', 'teacher.user:id,name', 'courseField:id,name_ar'])
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('teacher', fn ($t) => $t->where('user_id', $user->id)))
            ->when(
                $user->isAdmin() && $request->filled('owner'),
                fn ($q) => $q->where('teacher_id', $request->integer('owner'))
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($courses->through(fn (Course $course) => $this->resourceFor($course, $user)));
    }

    public function store(CreateCourseRequest $request)
    {
        $teacher = $request->user()->loadMissing('teacher')->teacher;

        $course = $this->courseService->createDraft($teacher, $request->validated());

        return $this->success($this->resourceFor($course, $request->user()), 'تم إنشاء مسودة الدورة بنجاح', 201);
    }

    /**
     * تصفح عام لدورات مركز موثّق النشطة فقط (للتسجيل) — بيانات غير حساسة، بلا Policy
     * خاص، بنفس منطق PackageController::indexForTeacher. المركز غير الموثّق لا تظهر دوراته.
     */
    public function indexForTeacher(Teacher $teacher)
    {
        abort_unless($teacher->isVerified(), 404);

        $courses = Course::query()
            ->where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->with(['courseField:id,name_ar', 'schedules'])
            ->latest()
            ->paginate(20);

        return $this->paginate($courses->through(fn (Course $course) => new StudentCourseResource($course)));
    }

    public function show(Request $request, Course $course)
    {
        $this->authorize('view', $course);

        $course->load(['curricula', 'schedules', 'teacher:id,user_id,teacher_type', 'teacher.user:id,name', 'courseField:id,name_ar']);

        return $this->success($this->resourceFor($course, $request->user()));
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        $course = $this->courseService->updateDraft($course, $request->validated());

        return $this->success($this->resourceFor($course, $request->user()), 'تم تحديث الدورة بنجاح');
    }

    public function submit(Request $request, Course $course)
    {
        $this->authorize('submit', $course);

        $course = $this->courseService->submitForApproval($course);

        return $this->success($this->resourceFor($course, $request->user()), 'تم إرسال الدورة للمراجعة');
    }

    public function disable(Request $request, Course $course)
    {
        $this->authorize('disable', $course);

        $course = $this->courseService->disable($course);

        return $this->success($this->resourceFor($course, $request->user()), 'تم تعطيل الدورة');
    }

    public function approve(ApproveCourseRequest $request, Course $course)
    {
        $course = $this->approvalService->approve(
            $course,
            $request->user(),
            (float) $request->validated('platform_margin_percent'),
        );

        return $this->success($this->resourceFor($course, $request->user()), 'تمت الموافقة على الدورة');
    }

    public function reject(RejectCourseRequest $request, Course $course)
    {
        $course = $this->approvalService->reject($course, $request->user(), $request->validated('reason'));

        return $this->success($this->resourceFor($course, $request->user()), 'تم رفض الدورة');
    }

    private function resourceFor(Course $course, User $user): AdminCourseResource|TeacherCourseResource|StudentCourseResource
    {
        if ($user->isAdmin()) {
            return new AdminCourseResource($course);
        }

        if ($user->loadMissing('teacher')->teacher?->id === $course->teacher_id) {
            return new TeacherCourseResource($course);
        }

        return new StudentCourseResource($course);
    }
}
