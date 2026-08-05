<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Resources\Session\ClassSessionResource;
use App\Models\ClassSession;
use Illuminate\Http\Request;

class ClassSessionController extends Controller
{
    private const EAGER_LOAD = [
        'teacher:id,user_id',
        'teacher.user:id,name,avatar_path',
        'attendees:id,class_session_id,student_id,attendance',
        'attendees.student:id,user_id',
        'attendees.student.user:id,name',
        'booking:id,package_id,student_id',
        'booking.package:id,title,session_format,subject_id',
        'booking.package.subject:id,name_ar',
        'course:id,title,subject_id',
        'course.subject:id,name_ar',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', ClassSession::class);

        $sessions = ClassSession::query()
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(
                $request->filled('package_id'),
                fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('package_id', $request->integer('package_id')))
            )
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when(
                $request->filled('student_id'),
                fn ($q) => $q->whereHas('attendees', fn ($a) => $a->where('student_id', $request->integer('student_id')))
            )
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to')))
            ->with(self::EAGER_LOAD)
            ->orderBy('scheduled_at')
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($sessions->through(fn (ClassSession $session) => new ClassSessionResource($session)));
    }

    public function show(ClassSession $session)
    {
        $this->authorize('view', $session);

        $session->load(self::EAGER_LOAD);

        return $this->success(new ClassSessionResource($session));
    }
}
