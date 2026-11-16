<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\SessionAttachment;
use App\Models\User;
use App\Notifications\SessionAttachmentUploaded;
use App\Traits\LogsAuditEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * تبادل الملفات بين المعلم والطالب مباشرة من صفحة الجلسة — بديل تمريرها عبر موظف.
 */
class SessionAttachmentService
{
    use LogsAuditEvents;

    public function __construct(private readonly NotificationService $notifications) {}

    public function upload(ClassSession $session, User $user, UploadedFile $file, ?string $title): SessionAttachment
    {
        $role = $this->roleFor($user, $session);

        $count = $session->attachments()->where('uploaded_by', $user->id)->count();
        if ($count >= SessionAttachment::MAX_PER_UPLOADER) {
            throw ValidationException::withMessages([
                'file' => 'وصلت للحد الأقصى من الملفات في هذه الجلسة ('.SessionAttachment::MAX_PER_UPLOADER.' ملفاً) — احذف ملفاً قديماً أولاً',
            ]);
        }

        $path = FileStorage::storeFile(
            $file,
            "session_attachments/{$session->id}",
            'attachment',
            SessionAttachment::DISK,
        );

        $attachment = SessionAttachment::create([
            'class_session_id' => $session->id,
            'uploaded_by' => $user->id,
            'uploader_role' => $role,
            'title' => $title,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        $this->audit('session_attachment.uploaded', $attachment, [], [
            'class_session_id' => $session->id,
            'original_name' => $attachment->original_name,
        ]);

        $attachment->setRelation('uploader', $user);
        $attachment->setRelation('session', $session);

        $this->recipientsFor($attachment, $session)
            ->each(fn (User $recipient) => $this->notifications->send(
                $recipient,
                new SessionAttachmentUploaded($attachment),
                'session.attachment_uploaded',
            ));

        return $attachment;
    }

    public function delete(SessionAttachment $attachment): void
    {
        Storage::disk(SessionAttachment::DISK)->delete($attachment->path);

        $this->audit('session_attachment.deleted', $attachment, [
            'class_session_id' => $attachment->class_session_id,
            'original_name' => $attachment->original_name,
        ]);

        $attachment->delete();
    }

    private function roleFor(User $user, ClassSession $session): string
    {
        if ($user->isAdmin()) {
            return 'admin';
        }

        return $user->loadMissing('teacher')->teacher?->id === $session->teacher_id ? 'teacher' : 'student';
    }

    /**
     * ملف الطالب → المعلم وحده (لا بقية طلاب المجموعة، فهم لا يرونه أصلاً).
     * ملف المعلم/الأدمن → كل طلاب الجلسة باشتراك قائم، والمعلم أيضاً إن رفعه الأدمن.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(SessionAttachment $attachment, ClassSession $session): Collection
    {
        $teacherUser = $session->loadMissing('teacher.user')->teacher?->user;

        if ($attachment->uploader_role === 'student') {
            return collect([$teacherUser])->filter();
        }

        $attendees = $session->attendees()->with('student.user');
        ClassSession::constrainToLiveAttendee($attendees);

        return $attendees->get()
            ->map(fn ($attendee) => $attendee->student?->user)
            ->push($attachment->uploader_role === 'admin' ? $teacherUser : null)
            ->filter()
            ->reject(fn (User $user) => $user->id === $attachment->uploaded_by)
            ->unique('id')
            ->values();
    }
}
