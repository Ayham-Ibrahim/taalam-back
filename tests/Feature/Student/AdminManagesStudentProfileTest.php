<?php

namespace Tests\Feature\Student;

use App\Models\CourseField;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الأدمن يكمل/يعدّل الملف الشخصي لطالب نيابة عنه، ويرفع/يحذف صورته — يوازي
 * تماماً ما يستطيعه المعلم لنفسه (وما يستطيعه الأدمن نيابة عن معلم):
 * StudentPolicy::update وُسِّعت لتقبل الأدمن أيضاً، لا الطالب نفسه فقط.
 */
class AdminManagesStudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_complete_a_students_academic_profile_on_their_behalf(): void
    {
        [$student, , $adminToken] = $this->createStudentAndAdmin();
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);

        $response = $this->as($adminToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_name' => 'أبو الطالب',
            'guardian_phone' => '0590000000',
        ]);

        $response->assertStatus(200);
        $fresh = $student->fresh();
        $this->assertSame('training', $fresh->education_type);
        $this->assertSame($field->id, $fresh->course_field_id);
        $this->assertSame('أبو الطالب', $fresh->guardian_name);
    }

    public function test_admin_can_upload_and_delete_a_students_avatar_on_their_behalf(): void
    {
        Storage::fake('public');

        [$student, , $adminToken] = $this->createStudentAndAdmin();

        $upload = $this->as($adminToken)->post("/api/students/{$student->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo.jpg', 500, 500),
        ]);

        $upload->assertStatus(200);
        $student->refresh()->loadMissing('user');
        $this->assertNotNull($student->user->avatar_path);

        $delete = $this->as($adminToken)->deleteJson("/api/students/{$student->id}/avatar");
        $delete->assertStatus(200);
        $this->assertNull($student->user->fresh()->avatar_path);
    }

    /** لا يمكن لطالب آخر (ليس صاحب الحساب ولا أدمن) تعديل ملف طالب غيره أو رفع صورة نيابة عنه */
    public function test_a_different_student_cannot_manage_another_students_profile(): void
    {
        [$student] = $this->createStudentAndAdmin();
        $intruderToken = User::factory()->student()->create()->createToken('t')->plainTextToken;
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);

        $update = $this->as($intruderToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_name' => 'أبو الطالب',
            'guardian_phone' => '0590000000',
        ]);
        $update->assertStatus(403);

        $upload = $this->as($intruderToken)->post("/api/students/{$student->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo.jpg', 500, 500),
        ]);
        $upload->assertStatus(403);
    }

    /** الطالب نفسه ما زال قادراً على تعديل ملفه — اختبار ارتداد (regression) لتوسيع الصلاحية */
    public function test_student_can_still_update_their_own_profile(): void
    {
        [$student, $studentToken] = $this->createStudentAndAdmin();
        $field = CourseField::create(['code' => 'f-'.uniqid(), 'name_ar' => 'مجال']);

        $response = $this->as($studentToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $field->id,
            'level' => 'beginner',
            'guardian_name' => 'أبو الطالب',
            'guardian_phone' => '0590000000',
        ]);

        $response->assertStatus(200);
    }

    /**
     * @return array{0: Student, 1: string, 2: string}
     */
    private function createStudentAndAdmin(): array
    {
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'school']);
        $admin = User::factory()->admin()->create();

        return [$student, $user->createToken('t')->plainTextToken, $admin->createToken('t')->plainTextToken];
    }
}
