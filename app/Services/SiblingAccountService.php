<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حسابات الإخوة: الطالب الأساسي حساب طبيعي تماماً، وكل أخ يضيفه حساب user +
 * student مستقل (primary_user_id = الأساسي) — فيبقى كل منطق الحجز/التعارض/الدفع/
 * الجلسات كما هو لأن لكل أخ student_id خاصاً. حساب الأخ بلا إيميل ولا كلمة
 * مرور، فلا يدخل إلا بالتبديل (switchTo) من داخل حساب العائلة نفسها.
 */
class SiblingAccountService
{
    use LogsAuditEvents;

    public function add(User $primary, array $data): User
    {
        return DB::transaction(function () use ($primary, $data) {
            $sibling = User::create([
                'name' => $data['name'],
                'email' => null,
                'gender' => $data['gender'] ?? null,
                'role' => 'student',
                'primary_user_id' => $primary->id,
                'password' => null,
                'locale' => $primary->locale,
                // نفس منطقة الأساسي — نفس الأسرة ونفس الجهاز غالباً، ومواعيد الجلسات تُحسب بها
                'timezone' => $primary->timezone,
                'timezone_auto' => $primary->timezone_auto,
                'is_active' => true,
            ]);

            $student = Student::create([
                'user_id' => $sibling->id,
                'education_type' => $data['education_type'],
                'curriculum_id' => $data['curriculum_id'] ?? null,
                'stage_id' => $data['stage_id'] ?? null,
                'grade' => $data['grade'] ?? null,
                'university_id' => $data['university_id'] ?? null,
                'major_id' => $data['major_id'] ?? null,
                'academic_level' => $data['academic_level'] ?? null,
                'course_field_id' => $data['course_field_id'] ?? null,
                'level' => $data['level'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'guardian_name' => $data['guardian_name'] ?? null,
                'guardian_phone' => $data['guardian_phone'] ?? null,
            ]);

            $sibling->setRelation('student', $student);

            $this->audit('student.sibling_added', $student, [], ['primary_user_id' => $primary->id], null, $primary->id);

            return $sibling;
        });
    }

    /**
     * كل حسابات العائلة (الأساسي أولاً ثم الإخوة) — بصرف النظر عمّا إذا كان
     * المستدعي هو الأساسي أو أحد الإخوة.
     *
     * @return Collection<int, User>
     */
    public function family(User $user): Collection
    {
        $primaryId = $user->primary_user_id ?? $user->id;

        return User::query()
            ->where(fn ($q) => $q->whereKey($primaryId)->orWhere('primary_user_id', $primaryId))
            ->where('role', 'student')
            ->with('student:id,user_id,education_type,stage_id,university_id,grade')
            ->orderByRaw('primary_user_id IS NOT NULL')
            ->orderBy('id')
            ->get();
    }

    /**
     * يبدّل الجلسة الحالية إلى حساب آخر من نفس العائلة: يُبطل التوكن الحالي
     * ويصدر توكناً للحساب الهدف (كتسجيل خروج ثم دخول) — لا تتراكم التوكنات.
     *
     * @return array{user: User, token: string}
     */
    public function switchTo(User $current, User $target): array
    {
        $primaryId = $current->primary_user_id ?? $current->id;
        $sameFamily = $target->id === $primaryId || $target->primary_user_id === $primaryId;

        if (! $sameFamily || $target->id === $current->id || ! $target->isStudent()) {
            abort(403, 'لا يمكنك التبديل إلى هذا الحساب');
        }

        $primary = $target->id === $primaryId ? $target : $target->primaryAccount;

        if (! $target->is_active || ! $primary?->is_active) {
            throw ValidationException::withMessages([
                'account' => ['هذا الحساب معطّل، يرجى التواصل مع الإدارة'],
            ]);
        }

        $current->currentAccessToken()?->delete();

        $target->load('student:id,user_id,education_type,stage_id,university_id');

        $this->audit('student.account_switched', $target, [], ['from_user_id' => $current->id], null, $current->id);

        return [
            'user' => $target,
            'token' => $target->createToken('api')->plainTextToken,
        ];
    }
}
