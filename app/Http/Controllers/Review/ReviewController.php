<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\CreateReviewRequest;
use App\Http\Requests\Review\HideReviewRequest;
use App\Http\Requests\Review\ReportReviewRequest;
use App\Http\Requests\Review\RespondToReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Http\Resources\Review\AdminReviewResource;
use App\Http\Resources\Review\MyReviewResource;
use App\Models\ClassSession;
use App\Models\Review;
use App\Models\Teacher;
use App\Services\ReviewService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    /** تقييمات الطالب الحالي هو نفسه — يغذّي تبويب "التقييمات" بلوحة تحكم الطالب */
    public function myReviews(Request $request)
    {
        $student = $request->user()->loadMissing('student')->student;

        $reviews = Review::where('student_id', $student?->id ?? 0)
            ->with('teacher.user:id,name,avatar_path')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($reviews->through(fn (Review $review) => new MyReviewResource($review)));
    }

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

    /**
     * ملخص تقييم المعلم للعرض العام — متوسط دقيق + توزيع النجوم كنِسب مئوية،
     * محسوب مباشرة من قاعدة البيانات (GROUP BY rating) لا من عيّنة الصفحة
     * الأولى. rating_avg/reviews_count المخزَّنان على المعلم يحدَّثان تلقائياً
     * عبر ReviewObserver عند كل إنشاء/تعديل/إخفاء تقييم.
     */
    public function ratingSummaryForTeacher(Teacher $teacher)
    {
        $counts = Review::where('teacher_id', $teacher->id)
            ->where('is_hidden', false)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $total = (int) $counts->sum();
        $sum = collect(range(1, 5))->sum(fn ($star) => $star * (int) ($counts[$star] ?? 0));

        $distribution = [];
        foreach (range(1, 5) as $star) {
            $starCount = (int) ($counts[$star] ?? 0);
            $distribution[$star] = $total > 0 ? (int) round($starCount / $total * 100) : 0;
        }

        return $this->success([
            'average' => $total > 0 ? round($sum / $total, 1) : 0,
            'total' => $total,
            'distribution' => $distribution,
        ]);
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
