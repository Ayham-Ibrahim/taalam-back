<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\CreateManualEnrollmentRequest;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Services\InvoicePdfService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use RuntimeException;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly PaymentService $paymentService,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Enrollment::class);

        $user = $request->user();

        $enrollments = Enrollment::query()
            ->with([
                'course:id,title,subject_id,total_sessions,session_duration_min',
                'course.subject:id,name_ar',
                'course.curricula:id,name_ar',
                'teacher:id,user_id',
                'teacher.user:id,name,avatar_path',
            ])
            ->visibleTo($user)
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($enrollments);
    }

    public function show(Request $request, Enrollment $enrollment)
    {
        $this->authorize('view', $enrollment);

        return $this->success($enrollment->load([
            'payments',
            'course:id,title,description,subject_id,total_sessions,session_duration_min',
            'course.subject:id,name_ar',
            'course.curricula:id,name_ar',
            'teacher.user:id,name,avatar_path',
        ]));
    }

    public function store(Request $request, Course $course)
    {
        $this->authorize('create', Enrollment::class);

        $student = $request->user()->loadMissing('student')->student;

        $enrollment = $this->enrollmentService->initiateEnrollment($student, $course);
        $checkoutUrl = $this->paymentService->createCheckoutSessionForEnrollment($enrollment);

        return $this->success([
            'enrollment' => $enrollment,
            'checkout_url' => $checkoutUrl,
        ], 'تم إنشاء التسجيل، أكمل الدفع لتأكيده', 201);
    }

    /**
     * إعادة محاولة الدفع لتسجيل ما زال بانتظار الدفع — الطالب قد يكون أغلق نافذة
     * Stripe الأولى بلا إكمال الدفع؛ بلا هذا المسار لا وسيلة لإعادة المحاولة إطلاقاً
     * (على عكس الحجوزات التي تملك BookingController::checkout المكافئ).
     */
    public function checkout(Request $request, Enrollment $enrollment)
    {
        $this->authorize('view', $enrollment);

        if ($enrollment->status !== 'pending_payment') {
            return $this->error('هذا التسجيل ليس بانتظار الدفع', 422);
        }

        $checkoutUrl = $this->paymentService->createCheckoutSessionForEnrollment($enrollment);

        return $this->success(['checkout_url' => $checkoutUrl]);
    }

    /** تنزيل فاتورة التسجيل كملف PDF — لا فاتورة قبل إتمام الدفع. */
    public function downloadInvoice(Request $request, Enrollment $enrollment)
    {
        $this->authorize('view', $enrollment);

        try {
            $pdf = $this->invoicePdfService->forEnrollment($enrollment);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $pdf->download("invoice-{$enrollment->reference}.pdf");
    }

    public function createManual(CreateManualEnrollmentRequest $request, Course $course)
    {
        $student = Student::findOrFail($request->validated('student_id'));

        $enrollment = $this->enrollmentService->createManualEnrollment(
            $student,
            $course,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success($enrollment, 'تم إنشاء التسجيل اليدوي بنجاح', 201);
    }
}
