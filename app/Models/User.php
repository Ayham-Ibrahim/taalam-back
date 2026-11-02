<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp',
        'role',
        'primary_user_id',
        'password',
        'avatar_path',
        'gender',
        'locale',
        'timezone',
        'timezone_auto',
        'is_active',
        'policy_accepted_at',
        'policy_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'timezone_auto' => 'boolean',
            'policy_accepted_at' => 'datetime',
            'policy_version' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function academicSupporter()
    {
        return $this->hasOne(AcademicSupporter::class);
    }

    /** الحساب الأساسي الذي أضاف هذا الأخ — null للحسابات العادية */
    public function primaryAccount()
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }

    /** الإخوة المضافون على هذا الحساب (فقط إن كان حساباً أساسياً) */
    public function siblingAccounts()
    {
        return $this->hasMany(User::class, 'primary_user_id');
    }

    public function isSiblingAccount(): bool
    {
        return $this->primary_user_id !== null;
    }

    /**
     * إيميل التواصل: إيميل الحساب نفسه، أو إيميل الحساب الأساسي لحساب أخ بلا
     * إيميل — يُستخدم للإشعارات (routeNotificationForMail) وبوابة الدفع.
     */
    public function contactEmail(): ?string
    {
        return $this->email ?? $this->primaryAccount?->email;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->contactEmail();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isAccountant(): bool
    {
        return $this->role === 'accountant';
    }

    public function isAcademicSupport(): bool
    {
        return $this->role === 'academic_support';
    }

    /** الأدمن أو المحاسب — كل ما هو مالي (المستحقات، الحجوزات/المدفوعات) */
    public function isFinance(): bool
    {
        return $this->isAdmin() || $this->isAccountant();
    }

    /**
     * تجاوز الإشعار الافتراضي (Illuminate\Auth\Notifications\ResetPassword) —
     * ذاك يبني رابطاً لمسار ويب Blade باسم "password.reset" غير موجود إطلاقاً
     * في تطبيق API خالص كهذا؛ App\Notifications\ResetPassword يشير مباشرة
     * لصفحة الفرونت اند (config('app.frontend_url')).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPassword($token));
    }
}
