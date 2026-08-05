<?php

namespace Tests\Feature\Student;

use App\Models\Curriculum;
use App\Models\Stage;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_valid_students_from_csv(): void
    {
        Notification::fake();

        Curriculum::create(['code' => 'national', 'name_ar' => 'وطني']);
        Stage::create(['code' => 'primary', 'name_ar' => 'ابتدائي', 'education_type' => 'school']);

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,education_type,curriculum_code,stage_code,grade
        أحمد علي,ahmad.import@example.com,0500000001,school,national,primary,5
        سارة محمد,sara.import@example.com,0500000002,school,national,primary,6
        CSV;

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($adminToken)->post('/api/students/import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('users', ['email' => 'ahmad.import@example.com', 'role' => 'student']);
        $this->assertDatabaseHas('students', ['imported' => 1]);
        $this->assertDatabaseHas('account_invitations', []);
    }

    public function test_import_reports_per_row_errors_without_aborting_the_batch(): void
    {
        Notification::fake();

        Curriculum::create(['code' => 'national', 'name_ar' => 'وطني']);
        Stage::create(['code' => 'primary', 'name_ar' => 'ابتدائي', 'education_type' => 'school']);

        $existing = User::factory()->student()->create(['email' => 'duplicate@example.com']);

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,education_type,curriculum_code,stage_code,grade
        طالب صحيح,valid.import@example.com,0500000003,school,national,primary,4
        طالب بدون منهج,missing.curriculum@example.com,0500000004,school,,,3
        طالب مكرر,duplicate@example.com,0500000005,school,national,primary,2
        CSV;

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($adminToken)->post('/api/students/import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.failed', 2);

        $errors = $response->json('data.errors');
        $this->assertCount(2, $errors);
        $this->assertSame(3, $errors[0]['row']); // الصف الثاني (بيانات) = الصف 3 في الملف
        $this->assertSame(4, $errors[1]['row']);

        $this->assertDatabaseHas('users', ['email' => 'valid.import@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'missing.curriculum@example.com']);
    }

    public function test_import_rejects_file_exceeding_max_rows_setting(): void
    {
        $this->seed(SettingsSeeder::class);
        app(SettingsService::class)->set('student_import_max_rows', '1');

        [, $adminToken] = $this->createAdmin();

        $csv = <<<'CSV'
        name,email,phone,education_type,curriculum_code,stage_code,grade
        طالب1,s1.import@example.com,,school,national,primary,1
        طالب2,s2.import@example.com,,school,national,primary,2
        CSV;

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($adminToken)->post('/api/students/import', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_non_admin_cannot_import_students(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $csv = "name,email,education_type\nطالب,x.import@example.com,school";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($token)->post('/api/students/import', ['file' => $file]);

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
