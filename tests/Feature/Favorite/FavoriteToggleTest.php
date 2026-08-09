<?php

namespace Tests\Feature\Favorite;

use App\Models\Course;
use App\Models\CourseField;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_toggle_favorite_teacher_on_and_off(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $studentUser = User::factory()->student()->create();
        Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $token = $studentUser->createToken('t')->plainTextToken;

        $add = $this->as($token)->postJson("/api/favorites/teachers/{$teacher->id}");
        $add->assertStatus(201)->assertJsonPath('data.favorited', true);

        $this->assertDatabaseHas('favorites', [
            'favoritable_type' => Teacher::class,
            'favoritable_id' => $teacher->id,
        ]);

        $remove = $this->as($token)->postJson("/api/favorites/teachers/{$teacher->id}");
        $remove->assertStatus(200)->assertJsonPath('data.favorited', false);

        $this->assertDatabaseMissing('favorites', [
            'favoritable_type' => Teacher::class,
            'favoritable_id' => $teacher->id,
        ]);
    }

    public function test_favorites_index_returns_a_unified_shape_for_teachers_and_courses(): void
    {
        $teacherUser = User::factory()->teacher()->create(['name' => 'أ. سالم']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 'fav-math', 'name_ar' => 'رياضيات', 'education_type' => 'school']);
        $teacher->subjects()->attach($subject->id);

        $centerUser = User::factory()->teacher()->create(['name' => 'مركز النخبة']);
        $center = Teacher::create(['user_id' => $centerUser->id, 'teacher_type' => 'training_center', 'status' => 'verified']);
        $field = CourseField::create(['code' => 'fav-field', 'name_ar' => 'برمجة']);
        $course = Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة بايثون',
            'course_field_id' => $field->id,
            'subject_id' => $subject->id,
            'start_date' => now()->addWeek(),
            'end_date' => now()->addWeeks(4),
            'total_sessions' => 8,
            'max_seats' => 10,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => true,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $studentUser = User::factory()->student()->create();
        Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $token = $studentUser->createToken('t')->plainTextToken;

        $this->as($token)->postJson("/api/favorites/teachers/{$teacher->id}")->assertStatus(201);
        $this->as($token)->postJson("/api/favorites/courses/{$course->id}")->assertStatus(201);

        $response = $this->as($token)->getJson('/api/favorites');

        $response->assertStatus(200);
        $items = collect($response->json('data'));

        $teacherItem = $items->firstWhere('type', 'teacher');
        $this->assertNotNull($teacherItem);
        $this->assertSame('أ. سالم', $teacherItem['teacher']['name']);
        $this->assertSame(['رياضيات'], $teacherItem['teacher']['subjects']);

        $courseItem = $items->firstWhere('type', 'course');
        $this->assertNotNull($courseItem);
        $this->assertSame('دورة بايثون', $courseItem['course']['title']);
        $this->assertSame($center->id, $courseItem['course']['teacherId']);
        $this->assertSame('مركز النخبة', $courseItem['course']['providerName']);
        $this->assertEquals(150, $courseItem['course']['price']);
    }
}
