<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function registerStudent(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'gender' => $data['gender'] ?? null,
                'role' => 'student',
                'password' => $data['password'],
            ]);

            $student = Student::create([
                'user_id' => $user->id,
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

            $user->setRelation('student', $student);

            return [
                'user' => $user,
                'token' => $user->createToken('api')->plainTextToken,
            ];
        });
    }

    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['هذا الحساب معطّل، يرجى التواصل مع الإدارة'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->loadRoleRelation($user);

        return [
            'user' => $user,
            'token' => $user->createToken('api')->plainTextToken,
        ];
    }

    /** يحمّل علاقة teacher أو student فقط حسب دور المستخدم — لا داعي لتحميل كليهما */
    public function loadRoleRelation(User $user): User
    {
        if ($user->isTeacher()) {
            $user->load(['teacher:id,user_id,teacher_type,status']);
        } elseif ($user->isStudent()) {
            $user->load(['student:id,user_id,education_type,stage_id,university_id']);
        }

        return $user;
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /** يستبدل الصورة السابقة (إن وُجدت) بدل تركها يتيمة على القرص العام */
    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $oldPath = $user->avatar_path ? Str::after($user->avatar_path, '/storage/') : null;

        $url = FileStorage::storeFile($file, 'avatars', 'img');

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $user->update(['avatar_path' => $url]);

        return $user;
    }

    /** بيانات الحساب الأساسية فقط (اسم/هاتف/واتساب/جنس) — بصرف النظر عن الدور */
    public function updateProfile(User $user, array $data): User
    {
        $updates = [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'gender' => $data['gender'] ?? null,
        ];

        // اختيار يدوي صريح لتفعيل/تعطيل الاكتشاف التلقائي من الإعدادات — يطغى دائماً على نتيجة syncTimezone التالية
        if (array_key_exists('timezone_auto', $data) && $data['timezone_auto'] !== null) {
            $updates['timezone_auto'] = (bool) $data['timezone_auto'];
            if (! $data['timezone_auto'] && ! empty($data['timezone'])) {
                $updates['timezone'] = $data['timezone'];
            }
        } elseif (! empty($data['timezone'])) {
            // قيمة منطقة زمنية بلا توضيح صريح لـ timezone_auto — يُفترض هذا اختياراً يدوياً من المستخدم
            $updates['timezone'] = $data['timezone'];
            $updates['timezone_auto'] = false;
        }

        $user->update($updates);

        return $user;
    }

    /** مزامنة صامتة من متصفح المستخدم — تُطبَّق فقط إن لم يُثبِّت المستخدم منطقته يدوياً من قبل (timezone_auto) */
    public function syncTimezone(User $user, string $timezone): User
    {
        if ($user->timezone_auto) {
            $user->update(['timezone' => $timezone]);
        }

        return $user;
    }

    public function updatePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! $user->password || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة'],
            ]);
        }

        $user->update(['password' => $newPassword]);

        // إبطال كل الجلسات الأخرى دفاعاً في العمق — الجلسة الحالية فقط تبقى صالحة
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
    }
}
