<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use App\Notifications\PasswordResetByAdmin;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Facades\DB;

class StudentService
{
    use LogsAuditEvents;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * الأدمن يضع البريد/كلمة المرور مباشرة — الحساب نشط فوراً بلا education_type
     * (NULL = الملف الشخصي غير مكتمل، الطالب يُكمله بنفسه عند أول دخول).
     */
    public function createByAdmin(array $data, User $admin): Student
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'student',
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $student = Student::create([
                'user_id' => $user->id,
            ]);

            $this->notifications->send($user, new AccountCreatedByAdmin('student', $data['password']), 'student.account_created');

            $this->audit('student.account_created', $student, [], ['email' => $user->email]);

            return $student;
        });
    }

    /** يملأ الطالب بياناته الأكاديمية بنفسه بعد أول دخول — نفس حقول التسجيل الذاتي (RegisterStudentRequest) */
    public function completeProfile(Student $student, array $data): Student
    {
        $student->fill($data);
        $student->save();

        return $student->fresh();
    }

    /**
     * الأدمن يضع كلمة مرور جديدة مباشرة (بلا حاجة لمعرفة القديمة) — يوازي
     * AuthService::updatePassword من ناحية إبطال الجلسات القائمة (دفاع في
     * العمق: كلمة مرور جديدة تُبطل كل دخول سابق فوراً)، ويُرسِلها للطالب
     * بالبريد (PasswordResetByAdmin) تماماً كما تُرسَل عند إنشاء الحساب.
     */
    public function resetPasswordByAdmin(Student $student, string $newPassword, User $admin): void
    {
        $student->loadMissing('user');
        $user = $student->user;

        $user->update(['password' => $newPassword]);
        $user->tokens()->delete();

        $this->notifications->send($user, new PasswordResetByAdmin($newPassword), 'student.password_reset_by_admin');

        $this->audit('student.password_reset_by_admin', $student, [], [], null, $admin->id);
    }
}
