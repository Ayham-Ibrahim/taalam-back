<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أوقات التوفّر الأسبوعية المعروضة في البروفايل ("أوقات التوفر هذا الأسبوع")
 * — جدول teacher_availability_times، منفصل تماماً عن availability_slots
 * (أيام فقط) الذي يغذّي منتقي أيام الباقة الفردية.
 */
class TeacherAvailabilityTimesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_add_and_remove_weekly_availability_windows(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $add = $this->as($token)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        $add->assertStatus(201);
        $this->assertDatabaseHas('teacher_availability_times', ['teacher_id' => $teacher->id, 'day_of_week' => 1]);
        $timeId = $add->json('data.id');

        $this->as($token)->deleteJson("/api/teachers/{$teacher->id}/availability-times/{$timeId}")->assertStatus(200);
        $this->assertDatabaseMissing('teacher_availability_times', ['id' => $timeId]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 2,
            'start_time' => '14:00',
            'end_time' => '13:00',
        ])->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_overlapping_window_on_the_same_day_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '12:00',
        ])->assertStatus(201);

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 3, 'start_time' => '11:00', 'end_time' => '13:00',
        ])->assertStatus(422)->assertJsonValidationErrors('start_time');

        // نافذة ثانية غير متداخلة في نفس اليوم — مقبولة
        $this->as($token)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '15:00',
        ])->assertStatus(201);
    }

    public function test_a_different_teacher_cannot_manage_another_teachers_availability_times(): void
    {
        [$teacher] = $this->createTeacher();
        [, $intruderToken] = $this->createTeacher();

        $this->as($intruderToken)->postJson("/api/teachers/{$teacher->id}/availability-times", [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertStatus(403);
    }

    public function test_availability_times_appear_grouped_by_day_on_the_public_profile(): void
    {
        [$teacher] = $this->createTeacher(['status' => 'verified']);
        $teacher->availabilityTimes()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'sort_order' => 0]);
        $teacher->availabilityTimes()->create(['day_of_week' => 1, 'start_time' => '14:00', 'end_time' => '16:00', 'sort_order' => 1]);

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.availability_times.0.day_of_week', 1)
            ->assertJsonPath('data.availability_times.0.slots.0.start', '09:00')
            ->assertJsonPath('data.availability_times.0.slots.1.start', '14:00');
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createTeacher(array $overrides = []): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(array_merge(['user_id' => $user->id, 'teacher_type' => 'school'], $overrides));

        return [$teacher, $user->createToken('t')->plainTextToken];
    }
}
