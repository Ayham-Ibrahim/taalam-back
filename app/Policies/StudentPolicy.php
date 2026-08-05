<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    /** بحث/فهرسة الطلاب — أدمن فقط (تُستخدم لمنتقي الحجز اليدوي) */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** إنشاء حساب طالب مباشرة بإيميل/كلمة مرور — أدمن فقط، يوازي TeacherPolicy::invite */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** إكمال الملف الشخصي بعد أول دخول — الطالب نفسه فقط */
    public function update(User $user, Student $student): bool
    {
        return $user->id === $student->user_id;
    }

    /**
     * الأدمن يرى أي طالب، الطالب يرى نفسه، والمعلم يرى فقط طالباً له معه
     * حجز أو تسجيل فعلي — لا يرى ملفات طلاب لم يتعامل معهم إطلاقاً.
     */
    public function view(User $user, Student $student): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isStudent()) {
            return $user->loadMissing('student')->student?->id === $student->id;
        }

        if ($user->isTeacher()) {
            $teacherId = $user->loadMissing('teacher')->teacher?->id;

            if (! $teacherId) {
                return false;
            }

            return Booking::where('teacher_id', $teacherId)->where('student_id', $student->id)->exists()
                || Enrollment::where('teacher_id', $teacherId)->where('student_id', $student->id)->exists();
        }

        return false;
    }
}
