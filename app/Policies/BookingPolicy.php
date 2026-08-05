<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent() || $user->isTeacher();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->isAdmin()
            || $user->loadMissing('student')->student?->id === $booking->student_id
            || $user->loadMissing('teacher')->teacher?->id === $booking->teacher_id;
    }

    public function create(User $user): bool
    {
        return $user->loadMissing('student')->student !== null;
    }

    public function createManual(User $user): bool
    {
        return $user->isAdmin();
    }

    /** الموافقة/الرفض على طلب حجز فردي — المعلم صاحب الباقة أو الأدمن فقط */
    public function respondToRequest(User $user, Booking $booking): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $booking->teacher_id;
    }
}
