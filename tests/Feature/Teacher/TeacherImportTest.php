<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_valid_teachers_from_csv(): void
    {
        Notification::fake();

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,teacher_type
        أحمد المعلم,ahmad.teacher.import@example.com,0500000001,school
        مركز النخبة,elite.center.import@example.com,0500000002,training_center
        CSV;

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->as($adminToken)->post('/api/teachers/import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('users', ['email' => 'ahmad.teacher.import@example.com', 'role' => 'teacher']);
        $this->assertDatabaseHas('teachers', ['teacher_type' => 'school', 'status' => 'invited']);
        $this->assertDatabaseHas('teachers', ['teacher_type' => 'training_center', 'status' => 'invited']);
        $this->assertDatabaseHas('account_invitations', []);
    }

    public function test_import_reports_per_row_errors_without_aborting_the_batch(): void
    {
        Notification::fake();

        $existing = User::factory()->teacher()->create(['email' => 'duplicate.teacher@example.com']);

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,teacher_type
        معلم صحيح,valid.teacher.import@example.com,0500000003,school
        معلم بنوع خاطئ,invalid.type.import@example.com,0500000004,invalid_type
        معلم مكرر,duplicate.teacher@example.com,0500000005,school
        CSV;

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->as($adminToken)->post('/api/teachers/import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.failed', 2);

        $errors = $response->json('data.errors');
        $this->assertCount(2, $errors);
        $this->assertSame(3, $errors[0]['row']); // الصف الثاني (بيانات) = الصف 3 في الملف
        $this->assertSame(4, $errors[1]['row']);

        $this->assertDatabaseHas('users', ['email' => 'valid.teacher.import@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'invalid.type.import@example.com']);
    }

    public function test_import_rejects_file_exceeding_max_rows_setting(): void
    {
        $this->seed(SettingsSeeder::class);
        app(SettingsService::class)->set('teacher_import_max_rows', '1');

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,teacher_type
        معلم1,t1.import@example.com,,school
        معلم2,t2.import@example.com,,school
        CSV;

        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->as($adminToken)->post('/api/teachers/import', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_non_admin_cannot_import_teachers(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $csv = "name,email,teacher_type\nمعلم,x.teacher.import@example.com,school";
        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->as($token)->post('/api/teachers/import', ['file' => $file]);

        $response->assertStatus(403);
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
