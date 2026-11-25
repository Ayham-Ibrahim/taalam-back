<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * حسابات الإخوة: الطالب الأساسي حساب طبيعي تماماً، وكل أخ يضيفه حساب user +
 * student مستقل (primary_user_id = الأساسي) — فيبقى كل منطق الحجز/التعارض/الدفع/
 * الجلسات كما هو لأن لكل أخ student_id خاصاً. حساب الأخ بلا إيميل لكن بكلمة
 * مرور خاصة: يدخل ببريد الأساسي + كلمة مروره (AuthService::login)، أو بالتبديل
 * (switchTo) من داخل العائلة. لذا كلمات مرور العائلة يجب أن تختلف عن بعضها —
 * كلمة المرور وحدها تحدد أي حساب يقصده الداخل.
 */
class SiblingAccountService
{
    use LogsAuditEvents;

    public function add(User $primary, array $data): User
    {
        $this->ensurePasswordFreeInFamily($primary, $data['password'], exceptSelf: false);

        return DB::transaction(function () use ($primary, $data) {
            // نفس الأسرة غالباً بنفس الموقع — يُورَث من الأساسي ما لم يُحدَّد للأخ
            $location = filled($data['country_id'] ?? null) || filled($data['region_id'] ?? null)
                ? ['country_id' => $data['country_id'] ?? null, 'region_id' => $data['region_id'] ?? null]
                : ($primary->student()->first(['country_id', 'region_id'])?->only(['country_id', 'region_id']) ?? []);

            $sibling = User::create([
                'name' => $data['name'],
                'email' => null,
                'gender' => $data['gender'] ?? null,
                'role' => 'student',
                'primary_user_id' => $primary->id,
                'password' => $data['password'],
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
                ...$location,
                'created_by' => $primary->id,
            ]);

            $sibling->setRelation('student', $student);

            $this->audit('student.sibling_added', $student, [], ['primary_user_id' => $primary->id], null, $primary->id);

            return $sibling;
        });
    }

    /** الأساسي يضع كلمة مرور جديدة لأحد إخوته — بلا حاجة لمعرفة القديمة، وتُبطل جلسات الأخ القائمة */
    public function updatePassword(User $primary, User $sibling, string $password): void
    {
        if ($sibling->primary_user_id !== $primary->id) {
            abort(403, 'لا يمكنك تعديل كلمة مرور هذا الحساب');
        }

        $this->ensurePasswordFreeInFamily($sibling, $password);

        $sibling->update(['password' => $password]);
        $sibling->tokens()->delete();

        $this->audit('student.sibling_password_changed', $sibling, [], [], null, $primary->id);
    }

    /**
     * يرفض كلمة مرور يستخدمها حساب آخر من نفس العائلة (الدخول ببريد الأساسي
     * يميّز الحساب بكلمة المرور وحدها). exceptSelf: يُستثنى $member نفسه عند
     * تغيير كلمة مروره؛ عند إضافة أخ جديد يُفحص الأساسي أيضاً.
     */
    public function ensurePasswordFreeInFamily(User $member, string $password, bool $exceptSelf = true): void
    {
        $taken = $this->family($member)
            ->reject(fn (User $other) => ($exceptSelf && $other->id === $member->id) || ! $other->password)
            ->contains(fn (User $other) => Hash::check($password, $other->password));

        if ($taken) {
            throw ValidationException::withMessages([
                'password' => ['كلمة المرور مستخدمة لحساب آخر في العائلة، اختر كلمة مرور مختلفة'],
            ]);
        }
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
