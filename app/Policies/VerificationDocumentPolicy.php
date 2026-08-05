<?php

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;
use App\Models\VerificationDocument;

class VerificationDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, VerificationDocument $document): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $document->teacher_id;
    }

    public function create(User $user, Teacher $teacher): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $teacher->id;
    }

    public function review(User $user): bool
    {
        return $user->isAdmin();
    }
}
