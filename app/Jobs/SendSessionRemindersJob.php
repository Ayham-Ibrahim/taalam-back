<?php

namespace App\Jobs;

use App\Models\ClassSession;
use App\Notifications\SessionReminder;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تُشغَّل كل دقيقة (routes/console.php). ترسل رابط الجلسة تلقائياً للطالب والمعلم
 * معاً بمجرد دخول الجلسة ضمن مهلة session_reminder_minutes_before (120 دقيقة/ساعتان
 * افتراضياً) — reminder_sent_at يمنع الإرسال المكرر لنفس الجلسة.
 */
class SendSessionRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SettingsService $settings, NotificationService $notifications): void
    {
        $minutesBefore = (int) $settings->get('session_reminder_minutes_before', 120);

        $sessions = ClassSession::query()
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('join_url_student')
            ->whereBetween('scheduled_at', [now(), now()->addMinutes($minutesBefore)])
            ->with(['teacher.user', 'attendees.student.user'])
            ->get();

        foreach ($sessions as $session) {
            $this->notifyTeacher($session, $notifications);
            $this->notifyStudents($session, $notifications);

            $session->update(['reminder_sent_at' => now()]);
        }
    }

    private function notifyTeacher(ClassSession $session, NotificationService $notifications): void
    {
        $teacherUser = $session->teacher?->user;

        if (! $teacherUser || ! $session->join_url_teacher) {
            return;
        }

        $notifications->send($teacherUser, new SessionReminder($session, $session->join_url_teacher), 'session.reminder');
    }

    private function notifyStudents(ClassSession $session, NotificationService $notifications): void
    {
        if (! $session->join_url_student) {
            return;
        }

        foreach ($session->attendees as $attendee) {
            $studentUser = $attendee->student?->user;

            if ($studentUser) {
                $notifications->send($studentUser, new SessionReminder($session, $session->join_url_student), 'session.reminder');
            }
        }
    }
}
