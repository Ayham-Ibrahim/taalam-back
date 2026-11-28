<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use App\Notifications\TeacherEmailChangedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UpdateTeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_bio_up_to_500_characters_is_accepted(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 500);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(200)->assertJsonPath('data.bio', $bio);
    }

    public function test_bio_over_500_characters_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 501);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(422)->assertJsonValidationErrors('bio');
    }

    /**
     * فاليديشن صارم على مستوى الباك اند أيضاً — أحرف فقط بلا أرقام لاسم العرض
     * بالإنجليزية، بنفس قاعدة حقل "الاسم" في نافذة إضافة معلم عند الأدمن.
     */
    public function test_display_name_en_with_digits_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['display_name_en' => 'Center123']);

        $response->assertStatus(422)->assertJsonValidationErrors('display_name_en');
    }

    public function test_valid_display_name_en_is_accepted_for_a_training_center(): void
    {
        [, $token] = $this->createTeacher();
        $centerUser = User::factory()->teacher()->create();
        $center = Teacher::create(['user_id' => $centerUser->id, 'teacher_type' => 'training_center']);
        $centerToken = $centerUser->createToken('t')->plainTextToken;

        $response = $this->as($centerToken)->putJson("/api/teachers/{$center->id}", [
            'display_name_en' => 'Bright Future Academy',
            'commercial_register' => 'CR-12345',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.display_name_en', 'Bright Future Academy');
    }

    public function test_admin_can_change_teacher_type(): void
    {
        [$teacher] = $this->createTeacher();
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}", ['teacher_type' => 'university']);

        $response->assertStatus(200)->assertJsonPath('data.teacher_type', 'university');
    }

    /** المعلم لا يملك طريقاً لتبديل نوعه بنفسه عبر نفس المسار المشترك — يُتجاهَل بصمت لا 422 */
    public function test_teacher_cannot_change_their_own_type(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['teacher_type' => 'university']);

        $response->assertStatus(200);
        $this->assertSame('school', $teacher->fresh()->teacher_type);
    }

    /** تبديل النوع بعيداً عن المدرسي يمسح مناهجه القديمة بدل أن تبقى عالقة وتُسرَّب بفلتر مدرسي رغم تغيّر النوع */
    public function test_changing_type_away_from_school_clears_curricula(): void
    {
        [$teacher] = $this->createTeacher();
        $curriculum = \App\Models\Curriculum::create(['code' => 'NAT', 'name_ar' => 'المنهج الوطني']);
        $teacher->curricula()->sync([$curriculum->id]);
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        // university لا حقول إلزامية إضافية لها خلافاً لـ training_center — يبقي الاختبار مركّزاً على المناهج فقط
        $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}", ['teacher_type' => 'university'])
            ->assertStatus(200);

        $this->assertCount(0, $teacher->fresh()->curricula);
    }

    /** تبديل النوع بعيداً عن مركز تدريب يمسح اسمه بالإنجليزية وسجله التجاري بدل أن يبقيا عالقين بقيم لا تخص النوع الجديد */
    public function test_changing_type_away_from_training_center_clears_its_fields(): void
    {
        $centerUser = User::factory()->teacher()->create();
        $center = Teacher::create([
            'user_id' => $centerUser->id,
            'teacher_type' => 'training_center',
            'display_name_en' => 'Bright Future Academy',
            'commercial_register' => 'CR-12345',
        ]);
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($adminToken)->putJson("/api/teachers/{$center->id}", ['teacher_type' => 'university'])
            ->assertStatus(200);

        $fresh = $center->fresh();
        $this->assertNull($fresh->display_name_en);
        $this->assertNull($fresh->commercial_register);
    }

    public function test_admin_can_change_teacher_email(): void
    {
        Notification::fake();
        [$teacher] = $this->createTeacher();
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}", ['email' => 'new-email@example.com']);

        $response->assertStatus(200)->assertJsonPath('data.user.email', 'new-email@example.com');
        $this->assertSame('new-email@example.com', $teacher->fresh()->user->email);
        Notification::assertSentTo($teacher->user, TeacherEmailChangedByAdmin::class);
    }

    /** تعديل حقل آخر غير الإيميل لا يُطلق إشعار "تغيّر البريد" بالخطأ */
    public function test_updating_other_fields_does_not_send_email_changed_notification(): void
    {
        Notification::fake();
        [$teacher, $token] = $this->createTeacher();

        $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => 'نبذة جديدة'])->assertStatus(200);

        Notification::assertNotSentTo($teacher->user, TeacherEmailChangedByAdmin::class);
    }

    /** المعلم لا يملك طريقاً لتبديل بريده بنفسه عبر نفس المسار المشترك — يُتجاهَل بصمت لا 422 */
    public function test_teacher_cannot_change_their_own_email(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $originalEmail = $teacher->user->email;

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['email' => 'hijacked@example.com']);

        $response->assertStatus(200);
        $this->assertSame($originalEmail, $teacher->fresh()->user->email);
    }

    public function test_admin_cannot_set_a_duplicate_teacher_email(): void
    {
        [$teacher] = $this->createTeacher();
        $otherUser = User::factory()->teacher()->create(['email' => 'taken@example.com']);
        Teacher::create(['user_id' => $otherUser->id, 'teacher_type' => 'school']);
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}", ['email' => 'taken@example.com']);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }
}
