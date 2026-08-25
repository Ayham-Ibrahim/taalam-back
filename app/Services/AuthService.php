<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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

    /**
     * يحذف الصورة الحالية فقط (إن وُجدت) ويعيد المستخدم للصورة الافتراضية —
     * الأحرف الأولى من الاسم في الواجهة (نفس مبدأ فيسبوك)، لا حاجة لتخزين أي
     * "صورة افتراضية" فعلية هنا. عملية آمنة التكرار: بلا أي أثر إن لم تكن هناك
     * صورة أصلاً.
     */
    public function deleteAvatar(User $user): User
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete(Str::after($user->avatar_path, '/storage/'));
            $user->update(['avatar_path' => null]);
        }

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

    /**
     * لا نتحقق من وجود الحساب هنا ولا نُبلِّغ عن نتيجة الإرسال للمتحكم —
     * الكونترولر يُرجع دوماً رسالة عامة واحدة بصرف النظر عن الحالة الفعلية،
     * وإلا يصبح هذا المسار وسيلة لاكتشاف أي بريد إلكتروني مسجَّل لدينا فعلاً
     * (user enumeration) لمجرد تجربته ومراقبة اختلاف الرسالة.
     */
    public function sendPasswordResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(string $email, string $token, string $newPassword): void
    {
        $status = Password::reset(
            ['email' => $email, 'password' => $newPassword, 'token' => $token],
            function (User $user, string $password) {
                $user->update(['password' => $password]);

                // إبطال كل الجلسات القائمة — طلب إعادة تعيين كلمة المرور يعني
                // غالباً أن الجلسات الحالية (إن وُجدت) لم تعد تُمثِّل صاحب الحساب بالضرورة.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [$this->passwordResetStatusMessage($status)],
            ]);
        }
    }

    /** لا نظام ترجمة (lang/) في هذا المشروع — كل رسالة عربية مباشرة كبقية الخدمات، لا __() */
    private function passwordResetStatusMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'لا يوجد حساب مرتبط بهذا البريد الإلكتروني',
            Password::RESET_THROTTLED => 'يرجى الانتظار قليلاً قبل إعادة المحاولة',
            default => 'رابط إعادة تعيين كلمة المرور غير صالح أو منتهي الصلاحية',
        };
    }
}
