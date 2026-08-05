<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\CreateReviewRequest;
use App\Http\Requests\Review\HideReviewRequest;
use App\Http\Requests\Review\ReportReviewRequest;
use App\Http\Requests\Review\RespondToReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Http\Resources\Review\AdminReviewResource;
use App\Models\ClassSession;
use App\Models\Review;
use App\Models\Teacher;
use App\Services\ReviewService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    /** لوحة إشراف الأدمن على كل التقييمات — يرى المخفي والمُبلَّغ عنه أيضاً */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Review::class);

        $reviews = Review::query()
            ->with(['student.user:id,name', 'teacher.user:id,name'])
            ->when($request->filled('is_hidden'), fn ($q) => $q->where('is_hidden', $request->boolean('is_hidden')))
            ->when($request->filled('is_reported'), fn ($q) => $q->where('is_reported', $request->boolean('is_reported')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($reviews->through(fn (Review $review) => new AdminReviewResource($review)));
    }

    public function indexForTeacher(Request $request, Teacher $teacher)
    {
        $reviews = Review::where('teacher_id', $teacher->id)
            ->where('is_hidden', false)
            ->with('student.user:id,name,avatar_path')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($reviews);
    }

    public function store(CreateReviewRequest $request, ClassSession $session)
    {
        $student = $request->user()->loadMissing('student')->student;

        $review = $this->reviewService->create(
            $student,
            $session,
            (int) $request->validated('rating'),
            $request->validated('comment'),
        );

        return $this->success($review, 'تم إرسال التقييم بنجاح', 201);
    }

    public function update(UpdateReviewRequest $request, Review $review)
    {
        $student = $request->user()->loadMissing('student')->student;

        $review = $this->reviewService->update(
            $review,
            $student,
            (int) $request->validated('rating'),
            $request->validated('comment'),
        );

        return $this->success($review, 'تم تحديث التقييم بنجاح');
    }

    public function respond(RespondToReviewRequest $request, Review $review)
    {
        $teacher = $request->user()->loadMissing('teacher')->teacher;

        $review = $this->reviewService->respond($review, $teacher, $request->validated('response'));

        return $this->success($review, 'تم إرسال الرد بنجاح');
    }

    public function hide(HideReviewRequest $request, Review $review)
    {
        $review = $this->reviewService->hide($review, $request->user(), $request->validated('reason'));

        return $this->success($review, 'تم إخفاء التقييم');
    }

    public function unhide(Request $request, Review $review)
    {
        $this->authorize('unhide', Review::class);

        $review = $this->reviewService->unhide($review, $request->user());

        return $this->success($review, 'تم إظهار التقييم');
    }

    public function report(ReportReviewRequest $request, Review $review)
    {
        $review = $this->reviewService->report($review, $request->validated('reason'));

        return $this->success($review, 'تم الإبلاغ عن التقييم');
    }
}
