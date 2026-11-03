<?php

namespace Tests\Feature\Student;

use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حسابات الإخوة: الطالب الأساسي حساب طبيعي يضيف إخوته كحسابات طلاب مستقلة
 * (بلا إيميل) ويبدّل بينها — وكل منهم يشترك بالباقات بشكل مستقل تماماً.
 */
class SiblingAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_student_can_add_a_sibling_without_email(): void
    {
        [$primary, $token] = $this->createPrimaryStudent();

        $response = $this->as($token)->postJson('/api/me/siblings', [
            'name' => 'سارة',
            'gender' => 'female',
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'سارة')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.is_sibling_account', true)
            ->assertJsonPath('data.primary_user_id', $primary->id);

        $sibling = User::where('primary_user_id', $primary->id)->firstOrFail();
        $this->assertNull($sibling->password);
        $this->assertSame('student', $sibling->role);
        $this->assertSame($primary->fresh()->timezone, $sibling->timezone);
        $this->assertDatabaseHas('students', ['user_id' => $sibling->id, 'education_type' => 'training']);
    }

    public function test_sibling_account_cannot_add_siblings(): void
    {
        [$primary] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);

        $this->as($sibling->createToken('t')->plainTextToken)->postJson('/api/me/siblings', [
            'name' => 'آخر',
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
        ])->assertStatus(403);
    }

    public function test_non_student_cannot_add_siblings(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->as($teacher->createToken('t')->plainTextToken)->postJson('/api/me/siblings', [
            'name' => 'آخر',
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
        ])->assertStatus(403);
    }

    public function test_family_list_is_the_same_from_primary_and_sibling(): void
    {
        [$primary, $token] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);
        $this->createPrimaryStudent(); // عائلة أخرى — لا تظهر

        $this->as($token)->getJson('/api/me/siblings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $primary->id)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.id', $sibling->id)
            ->assertJsonPath('data.1.is_current', false);

        $this->as($sibling->createToken('t')->plainTextToken)->getJson('/api/me/siblings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.is_current', true);
    }

    public function test_switching_to_sibling_and_back_issues_new_token_and_revokes_old_one(): void
    {
        [$primary, $token] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);

        $response = $this->as($token)->postJson("/api/me/switch-account/{$sibling->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $sibling->id);

        $siblingToken = $response->json('data.token');

        $this->as($siblingToken)->getJson('/api/auth/me')->assertJsonPath('data.id', $sibling->id);
        $this->as($token)->getJson('/api/auth/me')->assertStatus(401);

        $back = $this->as($siblingToken)->postJson("/api/me/switch-account/{$primary->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $primary->id);

        $this->as($back->json('data.token'))->getJson('/api/auth/me')->assertJsonPath('data.id', $primary->id);
    }

    public function test_cannot_switch_to_an_account_outside_the_family(): void
    {
        [, $token] = $this->createPrimaryStudent();
        [$otherPrimary] = $this->createPrimaryStudent();
        $otherSibling = $this->createSibling($otherPrimary);

        $this->as($token)->postJson("/api/me/switch-account/{$otherSibling->id}")->assertStatus(403);
        $this->as($token)->postJson("/api/me/switch-account/{$otherPrimary->id}")->assertStatus(403);
    }

    public function test_cannot_switch_to_a_deactivated_sibling(): void
    {
        [$primary, $token] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);
        $sibling->update(['is_active' => false]);

        $this->as($token)->postJson("/api/me/switch-account/{$sibling->id}")->assertStatus(422);
    }

    public function test_primary_and_sibling_can_both_book_the_same_package(): void
    {
        [$primary, $token] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);
        $package = $this->createActiveGroupPackage();

        $this->as($token)->postJson("/api/packages/{$package->id}/bookings/group")->assertStatus(201);

        $siblingToken = $this->as($token)->postJson("/api/me/switch-account/{$sibling->id}")->json('data.token');
        $this->as($siblingToken)->postJson("/api/packages/{$package->id}/bookings/group")->assertStatus(201);

        $this->assertSame(2, DB::table('bookings')->where('package_id', $package->id)->count());
        $this->assertDatabaseHas('bookings', ['package_id' => $package->id, 'student_id' => $primary->student->id]);
        $this->assertDatabaseHas('bookings', ['package_id' => $package->id, 'student_id' => $sibling->student->id]);
    }

    public function test_sibling_notifications_are_routed_to_primary_email(): void
    {
        [$primary] = $this->createPrimaryStudent();
        $sibling = $this->createSibling($primary);

        $this->assertSame($primary->email, $sibling->routeNotificationForMail());
        $this->assertSame($primary->email, $primary->routeNotificationForMail());
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createPrimaryStudent(): array
    {
        $user = User::factory()->student()->create();
        Student::create(['user_id' => $user->id, 'education_type' => 'school']);

        return [$user->load('student'), $user->createToken('t')->plainTextToken];
    }

    private function createSibling(User $primary): User
    {
        $sibling = User::create([
            'name' => 'أخ',
            'email' => null,
            'role' => 'student',
            'primary_user_id' => $primary->id,
            'is_active' => true,
        ]);
        Student::create(['user_id' => $sibling->id, 'education_type' => 'school']);

        return $sibling->load('student');
    }

    private function courseFieldId(): int
    {
        return DB::table('course_fields')->insertGetId([
            'code' => 'cf-'.uniqid(), 'name_ar' => 'برمجة', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createActiveGroupPackage(): Package
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 'sib-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, 1);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة مجموعة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 10,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $sessionDate = Carbon::now()->addWeek();
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        return $package;
    }
}
