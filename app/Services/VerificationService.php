<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Notifications\VerificationDocumentRejected;
use App\Traits\LogsAuditEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    use LogsAuditEvents;

    public function __construct(private readonly NotificationService $notifications) {}

    public function uploadDocument(Teacher $teacher, UploadedFile $file, string $type): VerificationDocument
    {
        $path = FileStorage::storeFile(
            $file,
            "verification_documents/{$teacher->id}",
            'docs',
            VerificationDocument::DISK,
        );

        $document = VerificationDocument::create([
            'teacher_id' => $teacher->id,
            'type' => $type,
            's3_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
        ]);

        $this->audit('document.uploaded', $document, [], ['type' => $type]);

        return $document;
    }

    public function approveDocument(VerificationDocument $document, User $admin): VerificationDocument
    {
        $old = $document->only(['status']);

        $document->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->audit('document.approved', $document, $old, ['status' => 'approved']);

        return $document;
    }

    public function rejectDocument(VerificationDocument $document, User $admin, string $reason): VerificationDocument
    {
        $old = $document->only(['status']);

        $document->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->audit('document.rejected', $document, $old, ['status' => 'rejected'], $reason);

        $document->loadMissing('teacher.user');
        if ($document->teacher?->user) {
            $this->notifications->send($document->teacher->user, new VerificationDocumentRejected($document), 'document.rejected');
        }

        return $document;
    }

    public function grantBadge(Teacher $teacher, Badge $badge, User $admin): BadgeAward
    {
        $award = BadgeAward::create([
            'badge_id' => $badge->id,
            'teacher_id' => $teacher->id,
            'granted_by' => $admin->id,
            'granted_at' => now(),
        ]);

        $this->audit('badge.granted', $award, [], ['badge' => $badge->code]);

        return $award;
    }

    public function revokeBadge(BadgeAward $award, User $admin, ?string $reason = null): BadgeAward
    {
        if ($award->isRevoked()) {
            throw ValidationException::withMessages([
                'badge' => ['هذه الشارة ملغاة مسبقاً'],
            ]);
        }

        $award->update([
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
            'revoke_reason' => $reason,
        ]);

        $this->audit('badge.revoked', $award, [], ['revoke_reason' => $reason]);

        return $award;
    }
}
