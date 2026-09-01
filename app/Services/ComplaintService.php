<?php

namespace App\Services;

use App\Models\Complaint;
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
        private readonly SessionService $sessionService,
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

        if ($resolutionType === 'makeup_session' && $complaint->class_session_id) {
            $complaint->loadMissing('session');

            if ($complaint->session) {
                $this->sessionService->createMakeupSession($complaint->session);
            }
        }

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
