<?php

namespace App\Notifications;

use App\Models\VerificationDocument;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * تُرسَل للمعلم فور رفض الأدمن لوثيقة توثيق واحدة تحديداً (لا الحساب كاملاً —
 * ذلك التنبيه المنفصل موجود مسبقاً في TeacherVerificationReviewed) — قبل هذا
 * الإصلاح لم يكن أي طرف يُبلَّغ برفض وثيقة بعينها إطلاقاً، فلا يعلم المعلم
 * أي وثيقة يجب إعادة رفعها ولا لماذا رُفضت.
 */
class VerificationDocumentRejected extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    private const TYPE_LABELS = [
        'identity' => 'Identity Proof',
        'academic' => 'Academic Certificate',
        'experience' => 'Experience Certificate',
        'professional' => 'Professional Certificate',
        'security' => 'Security Clearance',
        'commercial' => 'Commercial Registration',
    ];

    public function __construct(private readonly VerificationDocument $document) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $typeLabel = self::TYPE_LABELS[$this->document->type] ?? $this->document->type;

        return (new MailMessage)
            ->subject('One of Your Verification Documents Was Declined')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your \"{$typeLabel}\" document was declined during review.")
            ->line('Reason: '.$this->document->rejection_reason)
            ->line('Please log in to your account and upload a new copy of this document so we can review it again.')
            ->action('Go to Your Profile', url('/complete-profile'));
    }

    public function toArray($notifiable): array
    {
        return [
            'documentId' => $this->document->id,
            'type' => $this->document->type,
            'reason' => $this->document->rejection_reason,
        ];
    }
}
