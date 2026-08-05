<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RecordAbsenceRequest;
use App\Http\Requests\Attendance\TeacherNoShowRequest;
use App\Models\ClassSession;
use App\Models\SessionAttendee;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SessionAttendanceController extends Controller
{
    public function __construct(private readonly SessionService $sessionService) {}

    public function markPresent(Request $request, SessionAttendee $attendee)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isTeacher(), 403);

        $attendee = $this->sessionService->markPresent($attendee);

        return $this->success($attendee, 'تم تسجيل الحضور');
    }

    public function recordAbsence(RecordAbsenceRequest $request, SessionAttendee $attendee)
    {
        $notifiedAt = $request->validated('notified_at') ? Carbon::parse($request->validated('notified_at')) : null;

        $attendee = $this->sessionService->recordStudentAbsence($attendee, $notifiedAt, $request->validated('reason'));

        return $this->success($attendee, 'تم تسجيل الغياب');
    }

    public function teacherNoShow(TeacherNoShowRequest $request, ClassSession $session)
    {
        $session = $this->sessionService->recordTeacherNoShow($session, $request->validated('notes'));

        return $this->success($session, 'تم تسجيل غياب المعلم وإنشاء جلسة تعويضية');
    }
}
