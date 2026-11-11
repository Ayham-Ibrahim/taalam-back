<?php

namespace Tests\Feature\Auth;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * المنطقة الزمنية للحساب تُحدَّد مرة واحدة عند إنشائه: من متصفح صاحب الحساب
 * حين يسجّل بنفسه أو يقبل دعوة، ومن اختيار الأدمن حين ينشئه الأدمن نيابة عنه.
 */
class AccountTimezoneCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registered_teacher_keeps_the_browser_timezone(): void
    {
        $this->postJson('/api/auth/register/teacher', [
            'name' => 'سالم أحمد',
            'email' => 'salem@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'timezone' => 'Africa/Cairo',
        ])->assertCreated();

        $this->assertSame('Africa/Cairo', User::where('email', 'salem@example.com')->value('timezone'));
    }

    public function test_registration_rejects_an_unknown_timezone(): void
    {
        $this->postJson('/api/auth/register/teacher', [
            'name' => 'سالم أحمد',
            'email' => 'salem@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'timezone' => 'Mars/Olympus',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    public function test_legacy_browser_timezone_names_are_accepted(): void
    {
        // بعض المتصفحات ما زالت تُرجع الأسماء القديمة (Asia/Calcutta بدل Asia/Kolkata)
        $this->postJson('/api/auth/register/teacher', [
            'name' => 'سالم أحمد',
            'email' => 'salem@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'timezone' => 'Asia/Calcutta',
        ])->assertCreated();
    }

    public function test_admin_picks_the_timezone_when_creating_a_student(): void
    {
        Notification::fake();
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->postJson('/api/students/create-account', [
            'name' => 'طالب',
            'email' => 'student@example.com',
            'password' => 'Password123!',
            'timezone' => 'Asia/Dubai',
        ])->assertCreated();

        $this->assertSame('Asia/Dubai', User::where('email', 'student@example.com')->value('timezone'));
    }

    public function test_admin_created_teacher_defaults_to_riyadh_without_a_choice(): void
    {
        Notification::fake();
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->postJson('/api/teachers/create-account', [
            'name' => 'سالم أحمد',
            'email' => 'salem@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
        ])->assertCreated();

        $this->assertSame('Asia/Riyadh', User::where('email', 'salem@example.com')->value('timezone'));
    }

    public function test_accepting_an_invitation_replaces_the_admins_choice_with_the_browser_timezone(): void
    {
        Notification::fake();
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->postJson('/api/teachers', [
            'name' => 'أستاذ أحمد',
            'email' => 'teacher@example.com',
            'teacher_type' => 'school',
            'timezone' => 'Asia/Amman',
        ])->assertCreated();

        $user = User::where('email', 'teacher@example.com')->firstOrFail();
        $this->assertSame('Asia/Amman', $user->timezone);

        $this->postJson('/api/teachers/accept-invitation', [
            'token' => AccountInvitation::where('user_id', $user->id)->value('token'),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'timezone' => 'Europe/Istanbul',
        ])->assertOk();

        $this->assertSame('Europe/Istanbul', $user->fresh()->timezone);
    }
}
