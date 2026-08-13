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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * تُشغَّل كل دقيقة (routes/console.php). ترسل رابط الجلسة تلقائياً للطالب والمعلم
 * معاً بمجرد دخول الجلسة ضمن مهلة session_reminder_minutes_before (120 دقيقة/ساعتان
 * افتراضياً) — reminder_sent_at يمنع الإرسال المكرر لنفس الجلسة.
 *
 * الإرسال هنا فوري (sendNow) وليس عبر send() العادية: هذه الـ Job نفسها مجدولة
 * ومحفوظة في طابور قابل لإعادة المحاولة، فدفع كل إشعار كـ Job منفصلة إضافية
 * (send العادية مع إشعار ShouldQueue) يعني قفزة طابور ثانية غير مراقَبة — إن لم
 * يعالجها worker حتى النهاية (توقف يدوي، تعطل مؤقت...) يصل التذكير لطرف
 * (المعلم مثلاً، لأنه يُعالَج أولاً) دون الآخر بصمت تام ودون أي إعادة محاولة،
 * لأن reminder_sent_at كان يُسجَّل فور "الدفع" للطابور لا فور "التسليم" الفعلي.
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

        $this->sendSafely($notifications, $session, $teacherUser, $session->join_url_teacher);
    }

    private function notifyStudents(ClassSession $session, NotificationService $notifications): void
    {
        if (! $session->join_url_student) {
            return;
        }

        foreach ($session->attendees as $attendee) {
            $studentUser = $attendee->student?->user;

            if ($studentUser) {
                $this->sendSafely($notifications, $session, $studentUser, $session->join_url_student);
            }
        }
    }

    /**
     * فشل إرسال تذكير لمستلم واحد يجب ألا يمنع محاولة إرساله للبقية، ولا أن
     * يمرّ دون أثر — يُسجَّل بوضوح في اللوغ (سبب الفشل الحقيقي + المستلم) بدل
     * أن يختفي داخل jobs/failed_jobs غير مرئية لأحد.
     */
    private function sendSafely(NotificationService $notifications, ClassSession $session, mixed $notifiable, string $joinUrl): void
    {
        try {
            $notifications->sendNow($notifiable, new SessionReminder($session, $joinUrl), 'session.reminder');
        } catch (Throwable $e) {
            Log::error('فشل إرسال تذكير الجلسة', [
                'class_session_id' => $session->id,
                'notifiable_type' => $notifiable::class,
                'notifiable_id' => $notifiable->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
