<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Review;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

/**
 * لا تقييم دون جلسة مكتملة حقيقية حضرها الطالب فعلاً (session_attendees) —
 * نفس المصدر الذي يحدد ملكية الحجز/التسجيل في M5/M6، وليس class_sessions.booking_id
 * مباشرة (لأنه في المجموعة يشير فقط لصاحب أول حجز).
 */
class ReviewService
{
    use LogsAuditEvents;

    public function __construct(private readonly SettingsService $settings) {}

    public function create(Student $student, ClassSession $session, int $rating, ?string $comment): Review
    {
        if ($session->status !== 'completed') {
            throw ValidationException::withMessages([
                'session' => ['يمكن تقييم الجلسات المكتملة فقط'],
            ]);
        }

        $attendee = SessionAttendee::where('class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->first();

        if (! $attendee) {
            throw ValidationException::withMessages([
                'session' => ['لم تحضر هذه الجلسة'],
            ]);
        }

        $windowDays = (int) $this->settings->get('review_window_days', 7);
        $referenceTime = $session->ended_at ?? $session->scheduled_at;

        if ($referenceTime && $referenceTime->diffInDays(now()) > $windowDays) {
            throw ValidationException::withMessages([
                'session' => ['انتهت مهلة تقييم هذه الجلسة'],
            ]);
        }

        if (Review::where('student_id', $student->id)->where('class_session_id', $session->id)->exists()) {
            throw ValidationException::withMessages([
                'session' => ['قمت بتقييم هذه الجلسة مسبقاً'],
            ]);
        }

        $editWindowHours = (int) $this->settings->get('review_edit_window_hours', 24);

        return Review::create([
            'student_id' => $student->id,
            'teacher_id' => $session->teacher_id,
            'class_session_id' => $session->id,
            'booking_id' => $attendee->booking_id,
            'enrollment_id' => $attendee->enrollment_id,
            'rating' => $rating,
            'comment' => $comment,
            'edit_deadline' => now()->addHours($editWindowHours),
        ]);
    }

    public function update(Review $review, Student $student, int $rating, ?string $comment): Review
    {
        if ($review->student_id !== $student->id) {
            throw ValidationException::withMessages([
                'review' => ['لا يمكنك تعديل تقييم غير خاص بك'],
            ]);
        }

        if ($review->edit_deadline && now()->greaterThan($review->edit_deadline)) {
            throw ValidationException::withMessages([
                'review' => ['انتهت مهلة تعديل هذا التقييم'],
            ]);
        }

        $review->update(['rating' => $rating, 'comment' => $comment]);

        return $review->fresh();
    }

    public function respond(Review $review, Teacher $teacher, string $response): Review
    {
        if ($review->teacher_id !== $teacher->id) {
            throw ValidationException::withMessages([
                'review' => ['هذا التقييم ليس لك'],
            ]);
        }

        if ($review->response !== null) {
            throw ValidationException::withMessages([
                'review' => ['تم الرد على هذا التقييم مسبقاً'],
            ]);
        }

        $review->update(['response' => $response, 'responded_at' => now()]);

        return $review->fresh();
    }

    public function hide(Review $review, User $admin, string $reason): Review
    {
        $review->update([
            'is_hidden' => true,
            'hidden_by' => $admin->id,
            'hidden_reason' => $reason,
        ]);

        $this->audit('review.hidden', $review, ['is_hidden' => false], ['is_hidden' => true], $reason, $admin->id);

        return $review->fresh();
    }

    public function unhide(Review $review, User $admin): Review
    {
        $review->update([
            'is_hidden' => false,
            'hidden_by' => null,
            'hidden_reason' => null,
        ]);

        $this->audit('review.unhidden', $review, ['is_hidden' => true], ['is_hidden' => false], null, $admin->id);

        return $review->fresh();
    }

    public function report(Review $review, string $reason): Review
    {
        $review->update(['is_reported' => true, 'report_reason' => $reason]);

        return $review->fresh();
    }
}
