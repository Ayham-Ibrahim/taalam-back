<?php

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\CourseField;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_training_center_can_create_draft_and_get_approved_with_frozen_price(): void
    {
        [, $centerToken] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'biz', 'name_ar' => 'أعمال']);

        $create = $this->as($centerToken)->postJson('/api/courses', [
            'title' => 'دورة إدارة أعمال',
            'course_field_id' => $field->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'total_sessions' => 10,
            'max_seats' => 20,
            'provider_price' => 200,
            'pricing_mode' => 'total',
            'has_certificate' => false,
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $courseId = $create->json('data.id');

        $create->assertJsonMissingPath('data.platform_margin_percent');
        $create->assertJsonMissingPath('data.platform_revenue');

        $submit = $this->as($centerToken)->postJson("/api/courses/{$courseId}/submit");
        $submit->assertStatus(200)->assertJsonPath('data.status', 'pending_approval');
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.submitted']);

        [, $adminToken] = $this->createAdmin();

        $approve = $this->as($adminToken)->postJson("/api/courses/{$courseId}/approve", [
            'platform_margin_percent' => 50,
        ]);

        $approve->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.platform_margin_percent', 50)
            ->assertJsonPath('data.student_price', 300)
            ->assertJsonPath('data.platform_revenue', 100);

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'status' => 'active',
            'provider_price' => 200,
            'platform_margin_percent' => 50,
            'student_price' => 300,
            'platform_revenue' => 100,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.approved']);

        // مجمَّد: لا يمكن تعديل الدورة النشطة إطلاقاً
        $editAttempt = $this->as($centerToken)->putJson("/api/courses/{$courseId}", [
            'title' => 'محاولة تعديل',
            'course_field_id' => $field->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'total_sessions' => 10,
            'max_seats' => 20,
            'provider_price' => 999,
            'pricing_mode' => 'total',
            'has_certificate' => false,
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);
        $editAttempt->assertStatus(422);

        $this->assertDatabaseHas('courses', ['id' => $courseId, 'provider_price' => 200, 'student_price' => 300]);
    }

    public function test_admin_can_reject_pending_course_with_reason(): void
    {
        [$teacher] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'tech', 'name_ar' => 'تقنية']);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title' => 'دورة برمجة',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'total_sessions' => 8,
            'max_seats' => 15,
            'provider_price' => 150,
            'status' => 'pending_approval',
            'submitted_at' => now(),
            ...$this->courseInfoFlags(),
        ]);

        [, $adminToken] = $this->createAdmin();

        $reject = $this->as($adminToken)->postJson("/api/courses/{$course->id}/reject", [
            'reason' => 'محتوى الدورة غير واضح',
        ]);

        $reject->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => 'rejected',
            'rejection_reason' => 'محتوى الدورة غير واضح',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.rejected']);
    }

    public function test_end_date_must_not_be_before_start_date(): void
    {
        [, $centerToken] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'art', 'name_ar' => 'فنون']);

        $response = $this->as($centerToken)->postJson('/api/courses', [
            'title' => 'دورة تواريخ خاطئة',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-01',
            'total_sessions' => 5,
            'max_seats' => 10,
            'provider_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_school_teacher_cannot_create_course(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher('school');
        $field = CourseField::create(['code' => 'sci', 'name_ar' => 'علوم']);

        $response = $this->as($teacherToken)->postJson('/api/courses', [
            'title' => 'دورة من مدرس مدرسي',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'total_sessions' => 5,
            'max_seats' => 10,
            'provider_price' => 100,
        ]);

        $response->assertStatus(403);
    }

    public function test_course_auto_closes_to_full_at_seat_capacity_and_reopens_when_freed(): void
    {
        [$teacher] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'lang', 'name_ar' => 'لغات']);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title' => 'دورة لغات',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'total_sessions' => 5,
            'max_seats' => 2,
            'enrolled_count' => 1,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'active',
            'approved_at' => now(),
            ...$this->courseInfoFlags(),
        ]);

        $course->update(['enrolled_count' => 2]);
        $this->assertSame('full', $course->fresh()->status);

        $course->update(['enrolled_count' => 1]);
        $this->assertSame('active', $course->fresh()->status);
    }

    public function test_admin_can_filter_courses_by_owner(): void
    {
        [$centerA] = $this->createVerifiedTeacher('training_center');
        [$centerB] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'own-c', 'name_ar' => 'مجال']);

        $courseA = Course::create([
            'teacher_id' => $centerA->id,
            'title' => 'دورة أ',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'total_sessions' => 5,
            'max_seats' => 10,
            'provider_price' => 100,
            'status' => 'draft',
            ...$this->courseInfoFlags(),
        ]);

        Course::create([
            'teacher_id' => $centerB->id,
            'title' => 'دورة ب',
            'course_field_id' => $field->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'total_sessions' => 5,
            'max_seats' => 10,
            'provider_price' => 100,
            'status' => 'draft',
            ...$this->courseInfoFlags(),
        ]);

        [, $adminToken] = $this->createAdmin();

        $filtered = $this->as($adminToken)->getJson("/api/courses?owner={$centerA->id}");
        $filtered->assertStatus(200);
        $ids = collect($filtered->json('data'))->pluck('id');
        $this->assertSame([$courseA->id], $ids->all());
    }

    public function test_admin_can_reenable_disabled_course(): void
    {
        [$teacher, $centerToken] = $this->createVerifiedTeacher('training_center');
        $course = $this->createDisabledCourse($teacher, maxSeats: 10, enrolled: 3);

        // صاحب الدورة لا يعيد تفعيلها — الإدارة فقط
        $this->as($centerToken)->postJson("/api/courses/{$course->id}/enable")->assertStatus(403);

        [, $adminToken] = $this->createAdmin();

        $this->as($adminToken)->postJson("/api/courses/{$course->id}/enable")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.enabled']);

        // ليست معطّلة الآن، فلا تُعاد مرة أخرى
        $this->as($adminToken)->postJson("/api/courses/{$course->id}/enable")->assertStatus(422);
    }

    public function test_reenabled_course_with_no_free_seats_becomes_full(): void
    {
        [$teacher] = $this->createVerifiedTeacher('training_center');
        $course = $this->createDisabledCourse($teacher, maxSeats: 2, enrolled: 2);

        [, $adminToken] = $this->createAdmin();

        $this->as($adminToken)->postJson("/api/courses/{$course->id}/enable")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'full');
    }

    public function test_training_center_can_disable_own_course_when_no_students_enrolled(): void
    {
        [$teacher, $centerToken] = $this->createVerifiedTeacher('training_center');
        $course = $this->createActiveCourse($teacher);

        $this->as($centerToken)->postJson("/api/courses/{$course->id}/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disabled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.disabled']);
    }

    /** طالب بانتظار الدفع يحجز مقعداً رغم أنه لم يُحسب في enrolled_count بعد */
    public function test_training_center_cannot_disable_course_with_enrolled_or_pending_students(): void
    {
        foreach (['pending_payment', 'confirmed'] as $status) {
            [$teacher, $centerToken] = $this->createVerifiedTeacher('training_center');
            $course = $this->createActiveCourse($teacher);
            $this->enrollStudent($course, $status);

            $this->as($centerToken)->postJson("/api/courses/{$course->id}/disable")
                ->assertStatus(422)
                ->assertJsonPath('errors.status.0', 'لا يمكن إيقاف الدورة لوجود طلاب مشتركين فيها');
            $this->assertSame('active', $course->fresh()->status);
        }
    }

    public function test_training_center_can_disable_course_whose_only_enrollment_was_cancelled(): void
    {
        [$teacher, $centerToken] = $this->createVerifiedTeacher('training_center');
        $course = $this->createActiveCourse($teacher);
        $this->enrollStudent($course, 'cancelled');

        $this->as($centerToken)->postJson("/api/courses/{$course->id}/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disabled');
    }

    public function test_admin_can_still_disable_course_with_enrolled_students(): void
    {
        [$teacher] = $this->createVerifiedTeacher('training_center');
        $course = $this->createActiveCourse($teacher);
        $this->enrollStudent($course, 'confirmed');

        [, $adminToken] = $this->createAdmin();

        $this->as($adminToken)->postJson("/api/courses/{$course->id}/disable")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disabled');
    }

    private function createActiveCourse(Teacher $teacher): Course
    {
        $course = $this->createDisabledCourse($teacher, maxSeats: 10, enrolled: 0);
        $course->update(['status' => 'active']);

        return $course;
    }

    private function enrollStudent(Course $course, string $status): void
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        Enrollment::create([
            'reference' => 'ENR-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => $status,
        ]);
    }

    private function createDisabledCourse(Teacher $teacher, int $maxSeats, int $enrolled): Course
    {
        $field = CourseField::create(['code' => 'dis-'.uniqid(), 'name_ar' => 'مجال']);

        return Course::create([
            'teacher_id' => $teacher->id,
            'title' => 'دورة معطّلة',
            'course_field_id' => $field->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-20',
            'total_sessions' => 5,
            'max_seats' => $maxSeats,
            'enrolled_count' => $enrolled,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'disabled',
            'approved_at' => now(),
            ...$this->courseInfoFlags(),
        ]);
    }

    /** الحقول البنيوية الإلزامية (NOT NULL بلا default) — لإنشاء Course مباشرة عبر Eloquent في الاختبارات */
    private function courseInfoFlags(): array
    {
        return [
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ];
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
