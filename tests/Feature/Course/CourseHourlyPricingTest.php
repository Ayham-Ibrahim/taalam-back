<?php

namespace Tests\Feature\Course;

use App\Models\CourseField;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseHourlyPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_pricing_mode_multiplies_provider_price_by_total_hours(): void
    {
        [, $centerToken] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'hr1', 'name_ar' => 'مجال']);

        $create = $this->as($centerToken)->postJson('/api/courses', [
            'title' => 'دورة بالساعة',
            'course_field_id' => $field->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'total_sessions' => 10,
            'total_hours' => 20,
            'provider_price' => 50,
            'pricing_mode' => 'hourly',
            'max_seats' => 20,
            'has_certificate' => false,
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $create->assertStatus(201)->assertJsonPath('data.pricing_mode', 'hourly');
        $courseId = $create->json('data.id');

        $this->as($centerToken)->postJson("/api/courses/{$courseId}/submit")->assertStatus(200);

        [, $adminToken] = $this->createAdmin();

        // 50 سعر الساعة × 20 ساعة = 1000 قبل الهامش، ثم ×1.5 = 1500 للطالب
        $approve = $this->as($adminToken)->postJson("/api/courses/{$courseId}/approve", [
            'platform_margin_percent' => 50,
        ]);

        $approve->assertStatus(200)
            ->assertJsonPath('data.student_price', 1500)
            ->assertJsonPath('data.platform_revenue', 500);

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'provider_price' => 50,
            'pricing_mode' => 'hourly',
            'student_price' => 1500,
            'platform_revenue' => 500,
        ]);
    }

    public function test_hourly_pricing_mode_requires_total_hours(): void
    {
        [, $centerToken] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'hr2', 'name_ar' => 'مجال']);

        $response = $this->as($centerToken)->postJson('/api/courses', [
            'title' => 'دورة بلا ساعات',
            'course_field_id' => $field->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'total_sessions' => 10,
            'provider_price' => 50,
            'pricing_mode' => 'hourly',
            'max_seats' => 20,
            'has_certificate' => false,
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['total_hours']);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(string $type): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => $type, 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }
}
