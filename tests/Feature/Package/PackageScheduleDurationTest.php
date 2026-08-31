<?php

namespace Tests\Feature\Package;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageScheduleDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_end_time_is_always_derived_from_the_fixed_session_duration(): void
    {
        DB::table('settings')->insert([
            'key' => 'session_duration_minutes', 'value' => '60', 'type' => 'integer',
            'group' => 'booking', 'label_ar' => 'x', 'is_editable' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'dur', 'name_ar' => 'مادة', 'education_type' => 'school']);

        $nextWednesday = now()->next(3);

        // ينطبق فقط على الجماعية الآن — الفردية لا تملك start_time/end_time على مستوى الباقة إطلاقاً.
        // الطلب لا يرسل end_time إطلاقاً — ولو أُرسل، يُتجاهل: القاعدة سياسة منصّة لا مدخل معلم
        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة',
            'description' => 'وصف الباقة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 5,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'schedules' => [
                ['date' => $nextWednesday->toDateString(), 'start_time' => '14:30'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('package_schedules', [
            'package_id' => $response->json('data.id'),
            'day_of_week' => 3,
            'start_time' => '14:30:00',
            'end_time' => '15:30:00',
        ]);
    }

    /**
     * @return array{0: \App\Models\Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = \App\Models\Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }
}
