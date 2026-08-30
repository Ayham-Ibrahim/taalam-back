<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreateManualBookingRequest;
use App\Http\Requests\Booking\RejectBookingRequest;
use App\Http\Requests\Booking\RequestIndividualBookingRequest;
use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Services\BookingService;
use App\Services\InvoicePdfService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use RuntimeException;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly PaymentService $paymentService,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Booking::class);
        $this->bookingService->expireStalePendingTeacherConfirmations();
        $this->bookingService->expireStalePendingPayments();

        $user = $request->user();

        $bookings = Booking::query()
            ->with([
                'package:id,title,session_format,subject_id',
                'package.subject:id,name_ar',
                'package.curricula:id,name_ar',
                'package.schedules:id,package_id,start_time,end_time',
                'teacher:id,user_id',
                'teacher.user:id,name,avatar_path',
                'student:id,user_id',
                'student.user:id,name,avatar_path',
            ])
            ->visibleTo($user)
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($bookings);
    }

    public function show(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);
        $this->bookingService->expireStalePendingTeacherConfirmations();
        $this->bookingService->expireStalePendingPayments();
        $booking->refresh();

        return $this->success($booking->load([
            'attendedSessions',
            'attendedSessions.teacher.user:id,name,avatar_path',
            'attendedSessions.rescheduleRequests' => fn ($q) => $q->select('id', 'class_session_id', 'requested_by', 'status', 'created_at')
                ->where('status', 'pending')
                ->latest(),
            'payments',
            'package:id,title,description,session_format,sessions_count',
            'package.subject:id,name_ar',
            'package.curricula:id,name_ar',
            'package.schedules:id,package_id,start_time,end_time',
            'teacher.user:id,name,avatar_path',
        ]));
    }

    /** الطالب يقدّم طلب حجز فردي — موعد مستقل لكل جلسة — بلا دفع بعد، بانتظار موافقة المعلم. */
    public function bookIndividual(RequestIndividualBookingRequest $request, Package $package)
    {
        $student = $request->user()->loadMissing('student')->student;

        $booking = $this->bookingService->requestIndividualBooking(
            $student,
            $package,
            $request->validated('slots'),
        );

        return $this->success($booking, 'تم إرسال طلب الحجز، بانتظار موافقة المعلم', 201);
    }

    /** المعلم يوافق على طلب حجز فردي — تُنشأ الجلسات ويُنشأ الدفع المعلَّق. */
    public function approveRequest(Request $request, Booking $booking)
    {
        $this->authorize('respondToRequest', $booking);
        $this->bookingService->expireStalePendingTeacherConfirmations();
        $booking->refresh();

        $booking = $this->bookingService->approveIndividualRequest($booking, $request->user());

        return $this->success($booking, 'تمت الموافقة على طلب الحجز');
    }

    /** المعلم يرفض طلب حجز فردي — لا شيء للاسترداد لأنه لم يُدفع بعد. */
    public function rejectRequest(RejectBookingRequest $request, Booking $booking)
    {
        $this->bookingService->expireStalePendingTeacherConfirmations();
        $booking->refresh();

        $booking = $this->bookingService->rejectIndividualRequest(
            $booking,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success($booking, 'تم رفض طلب الحجز');
    }

    /** الطالب يكمل الدفع بعد موافقة المعلم على طلبه. */
    public function checkout(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);

        if ($booking->status !== 'pending_payment') {
            return $this->error('هذا الحجز ليس بانتظار الدفع', 422);
        }

        $checkoutUrl = $this->paymentService->createCheckoutSessionForBooking($booking);

        return $this->success(['checkout_url' => $checkoutUrl]);
    }

    public function joinGroup(Request $request, Package $package)
    {
        $this->authorize('create', Booking::class);

        $student = $request->user()->loadMissing('student')->student;

        $booking = $this->bookingService->joinGroupPackage($student, $package);
        $checkoutUrl = $this->paymentService->createCheckoutSessionForBooking($booking);

        return $this->success([
            'booking' => $booking,
            'checkout_url' => $checkoutUrl,
        ], 'تم إنشاء الحجز، أكمل الدفع لتأكيده', 201);
    }

    /** تنزيل فاتورة الحجز كملف PDF — لا فاتورة قبل إتمام الدفع. */
    public function downloadInvoice(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);

        try {
            $pdf = $this->invoicePdfService->forBooking($booking);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->pdfDownload($pdf, "invoice-{$booking->reference}.pdf");
    }

    public function createManual(CreateManualBookingRequest $request, Package $package)
    {
        $student = Student::findOrFail($request->validated('student_id'));

        $booking = $this->bookingService->createManualBooking(
            $student,
            $package,
            $request->user(),
            $request->validated('reason'),
            $request->validated('slots'),
        );

        return $this->success($booking, 'تم إنشاء الحجز اليدوي بنجاح', 201);
    }
}
