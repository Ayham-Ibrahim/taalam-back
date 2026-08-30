<?php

namespace Tests\Feature\Student;

use App\Models\CourseField;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_birth_date_of_today_or_later_is_rejected_and_nothing_is_saved(): void
    {
        $user = User::factory()->student()->create();
        $student = Student::create([
            'user_id' => $user->id,
            'education_type' => 'training',
            'birth_date' => '2005-03-10',
        ]);
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);
        $token = $user->createToken('t')->plainTextToken;

        foreach ([now()->toDateString(), now()->addDay()->toDateString()] as $badDate) {
            $response = $this->as($token)->putJson("/api/students/{$student->id}", [
                'education_type' => 'training',
                'course_field_id' => $field->id,
                'level' => 'beginner',
                'birth_date' => $badDate,
            ]);

            $response->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        }

        // القيمة القديمة كما هي — لم يُحدَّث أي شيء
        $this->assertSame('2005-03-10', $student->fresh()->birth_date->toDateString());
        $this->assertNull($student->fresh()->course_field_id);
    }

    public function test_guardian_phone_rejects_non_numeric_values_and_nothing_is_saved(): void
    {
        $user = User::factory()->student()->create();
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);
        $student = Student::create([
            'user_id' => $user->id,
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_phone' => '0590000000',
        ]);
        $token = $user->createToken('t')->plainTextToken;

        foreach (['055abc', '123!@#', 'parentPhoneTest'] as $bad) {
            $this->as($token)->putJson("/api/students/{$student->id}", [
                'education_type' => 'training',
                'course_field_id' => $field->id,
                'level' => 'beginner',
                'guardian_phone' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors(['guardian_phone']);
        }

        $this->assertSame('0590000000', $student->fresh()->guardian_phone);
    }

    public function test_guardian_name_rejects_values_containing_digits_or_symbols(): void
    {
        $user = User::factory()->student()->create();
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);
        $student = Student::create([
            'user_id' => $user->id,
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_name' => 'أبو محمد',
        ]);
        $token = $user->createToken('t')->plainTextToken;

        foreach (['أبو 123', 'ولي أمر 55', '2024', 'Ahmad!@#'] as $bad) {
            $this->as($token)->putJson("/api/students/{$student->id}", [
                'education_type' => 'training',
                'course_field_id' => $field->id,
                'level' => 'beginner',
                'guardian_name' => $bad,
            ])->assertStatus(422)
                ->assertJsonPath('errors.guardian_name.0', 'اسم ولي الأمر يجب أن يحتوي على أحرف فقط من دون أرقام أو رموز.');
        }

        $this->assertSame('أبو محمد', $student->fresh()->guardian_name);
    }

    public function test_a_letters_only_guardian_name_is_accepted(): void
    {
        $user = User::factory()->student()->create();
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'training']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_name' => 'أبو عبد الرحمن',
        ])->assertStatus(200);

        $this->assertSame('أبو عبد الرحمن', $student->fresh()->guardian_name);
    }

    public function test_grade_rejects_non_numeric_values(): void
    {
        $user = User::factory()->student()->create();
        $curriculum = \App\Models\Curriculum::create(['code' => 'c-'.uniqid(), 'name_ar' => 'منهج']);
        $stage = \App\Models\Stage::create(['code' => 's-'.uniqid(), 'name_ar' => 'مرحلة', 'education_type' => 'school']);
        $student = Student::create([
            'user_id' => $user->id,
            'education_type' => 'school',
            'curriculum_id' => $curriculum->id,
            'stage_id' => $stage->id,
            'grade' => 5,
        ]);
        $token = $user->createToken('t')->plainTextToken;

        foreach (['Grade A', 'ثاني!@#', 'abc123', 0, 13] as $bad) {
            $this->as($token)->putJson("/api/students/{$student->id}", [
                'education_type' => 'school',
                'curriculum_id' => $curriculum->id,
                'stage_id' => $stage->id,
                'grade' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors(['grade']);
        }

        $this->assertSame(5, $student->fresh()->grade);
    }

    public function test_valid_guardian_phone_and_grade_are_accepted(): void
    {
        $user = User::factory()->student()->create();
        $curriculum = \App\Models\Curriculum::create(['code' => 'c-'.uniqid(), 'name_ar' => 'منهج']);
        $stage = \App\Models\Stage::create(['code' => 's-'.uniqid(), 'name_ar' => 'مرحلة', 'education_type' => 'school']);
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'school']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson("/api/students/{$student->id}", [
            'education_type' => 'school',
            'curriculum_id' => $curriculum->id,
            'stage_id' => $stage->id,
            'grade' => 7,
            'guardian_phone' => '+966555123456',
        ])->assertStatus(200);

        $fresh = $student->fresh();
        $this->assertSame(7, $fresh->grade);
        $this->assertSame('+966555123456', $fresh->guardian_phone);
    }

    public function test_a_past_birth_date_is_accepted(): void
    {
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'training']);
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'birth_date' => now()->subYears(18)->toDateString(),
        ])->assertStatus(200);

        $this->assertSame(now()->subYears(18)->toDateString(), $student->fresh()->birth_date->toDateString());
    }
}
