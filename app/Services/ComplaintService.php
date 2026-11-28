<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Message;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\ComplaintFiled;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * لا استرداد نقدي في هذا النظام — أنواع الحل محصورة بـ makeup_session/credit/
 * no_action/warning_issued (مطابقة لـ complaints.resolution_type في المخطط).
 */
class ComplaintService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * الطالب والمعلم كلاهما مؤهل لتقديم شكوى (مثلاً عبر نموذج "تواصل معنا"
     * العام) — بالضبط أحدهما غير null دائماً (FileComplaintRequest يفرض ذلك
     * عبر ComplaintPolicy::create، وقيد chk_complaints_filer يحميه في القاعدة).
     */
    public function file(?Student $student, ?Teacher $teacher, array $data): Complaint
    {
        $complaint = Complaint::create([
            'reference' => 'CM-'.strtoupper(Str::random(10)),
            'student_id' => $student?->id,
            'teacher_id' => $teacher?->id,
            'booking_id' => $data['booking_id'] ?? null,
            'class_session_id' => $data['class_session_id'] ?? null,
            'category' => $data['category'],
            'description' => $data['description'],
            'status' => 'open',
            'sla_due_at' => now()->addHours((int) $this->settings->get('sla_complaint_hours', 24)),
        ]);

        $this->notifyAdmins($complaint, $student !== null ? 'student' : 'teacher');

        return $complaint;
    }

    /**
     * زر الإبلاغ داخل المحادثة: الشكوى مرتبطة بالمحادثة والرسالة — هذا الربط وحده ما يفتح
     * للأدمن الاطلاع على المحادثة. filed_by_user_id دائماً (الداعم المستقل لا يملك
     * student/teacher)، و student_id/teacher_id متى كان للمُبلِّغ ملف منهما كي تظهر
     * الشكوى في قائمته كبقية شكاواه.
     */
    public function fileChatReport(Message $message, User $reporter, ?string $description): Complaint
    {
        // إبلاغ مكرر عن نفس الرسالة من نفس المستخدم وشكواه ما زالت مفتوحة: نفس الشكوى بلا
        // شكوى جديدة ولا بريد جديد لكل الأدمن (كان كل نقرة تُنشئ شكوى وتُرسل بريداً)
        $existing = Complaint::query()
            ->where('reported_message_id', $message->id)
            ->where('filed_by_user_id', $reporter->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $reporter->loadMissing(['student', 'teacher']);

        $complaint = Complaint::create([
            'reference' => 'CM-'.strtoupper(Str::random(10)),
            'student_id' => $reporter->student?->id,
            'teacher_id' => $reporter->student ? null : $reporter->teacher?->id,
            'filed_by_user_id' => $reporter->id,
            'conversation_id' => $message->conversation_id,
            'reported_message_id' => $message->id,
            'category' => 'chat_report',
            'description' => $description ?: 'إبلاغ عن رسالة في المحادثة',
            'status' => 'open',
            'sla_due_at' => now()->addHours((int) $this->settings->get('sla_complaint_hours', 24)),
        ]);

        $this->notifyAdmins($complaint, $reporter->role);

        return $complaint;
    }

    /**
     * قبل هذا الإصلاح: الشكوى تُسجَّل في القاعدة فقط دون إبلاغ أي أدمن —
     * تبقى معلَّقة حتى يفتح أحدهم لوحة الشكاوى يدوياً ليلاحظها. نفس نمط
     * RescheduleService::notifyRequestSubmitted (مطلوب دائماً القرار من أدمن).
     */
    private function notifyAdmins(Complaint $complaint, string $filerRole): void
    {
        User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $this->notifications->send(
                $admin,
                new ComplaintFiled($complaint->reference, $complaint->category, $filerRole),
                'complaint.filed',
            ));
    }

    public function resolve(Complaint $complaint, User $admin, string $resolutionType, ?string $notes = null): Complaint
    {
        if (in_array($complaint->status, ['resolved', 'closed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['هذه الشكوى مغلقة بالفعل'],
            ]);
        }

        $complaint->update([
            'status' => 'resolved',
            'resolution_type' => $resolutionType,
            'resolution_notes' => $notes,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);

        // makeup_session تصنيف للحل فقط — التعويضية تُنشأ من صفحة الجلسات (SessionService::scheduleMakeupByAdmin)
        // بشروطها: كانت تُنشأ هنا لأي جلسة بلا فحص، فتعويضية جلسة عُقدت تُسقط بالسقف مستحق جلسة عادية.

        $this->audit('complaint.resolved', $complaint, [], [
            'resolution_type' => $resolutionType,
        ], $notes, $admin->id);

        return $complaint->fresh();
    }

    public function escalate(Complaint $complaint): Complaint
    {
        $complaint->update(['status' => 'escalated']);

        return $complaint->fresh();
    }

    public function close(Complaint $complaint): Complaint
    {
        $complaint->update(['status' => 'closed']);

        return $complaint->fresh();
    }
}
