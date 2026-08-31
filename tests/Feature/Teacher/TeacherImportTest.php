<?php

namespace Tests\Feature\Teacher;

use App\Jobs\ProcessTeacherImportJob;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TeacherImportService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * يوازي Tests\Feature\Student\StudentImportTest تماماً — راجع تعليقه لتفصيل
 * سبب فصل اختبار endpoint (يتحقق فقط من دفع الوظيفة الصحيحة) عن اختبار
 * المعالجة الفعلية (TeacherImportService::processBatch مباشرة بمعزل عن الطابور).
 */
class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_import_request_queues_a_job_and_returns_a_queued_batch(): void
    {
        Queue::fake();
        // راجع تعليق StudentImportTest المطابق — بلا هذا يبقى ملف حقيقي يتيم على القرص.
        Storage::fake('local');

        [, $adminToken] = $this->createAdmin();

        $csv = "name,email,teacher_type\nأحمد المعلم,ahmad.teacher.import@example.com,school";
        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);

        $response = $this->as($adminToken)->post('/api/teachers/import', ['file' => $file]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', 'teacher')
            ->assertJsonPath('data.status', 'queued');

        $batchId = $response->json('data.id');
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'type' => 'teacher', 'status' => 'queued']);

        Queue::assertPushed(ProcessTeacherImportJob::class, fn ($job) => $job->importBatchId === $batchId);
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

    public function test_processing_a_batch_imports_valid_teachers_and_reports_per_row_errors(): void
    {
        Notification::fake();
        Storage::fake('local');

        $existing = User::factory()->teacher()->create(['email' => 'duplicate.teacher@example.com']);
        $admin = $this->createAdmin()[0];

        $csv = <<<'CSV'
        name,email,phone,teacher_type
        معلم صحيح,valid.teacher.import@example.com,0500000003,school
        معلم بنوع خاطئ,invalid.type.import@example.com,0500000004,invalid_type
        معلم مكرر,duplicate.teacher@example.com,0500000005,school
        CSV;

        $batch = $this->storeBatch($admin, $csv);

        app(TeacherImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(2, $batch->failed_count);
        $this->assertCount(2, $batch->errors);
        $this->assertSame(3, $batch->errors[0]['row']);
        $this->assertSame(4, $batch->errors[1]['row']);

        $this->assertDatabaseHas('users', ['email' => 'valid.teacher.import@example.com', 'role' => 'teacher']);
        $this->assertDatabaseHas('teachers', ['teacher_type' => 'school', 'status' => 'invited']);
        $this->assertDatabaseMissing('users', ['email' => 'invalid.type.import@example.com']);
    }

    /**
     * ملفات نماذج خارجية حقيقية (Google Forms) بعناوين أعمدة أطول وأثقل من
     * القالب البسيط — الحقول المنطقية تُقرأ منها أيضاً (name/email/phone/
     * whatsapp/gender/teacher_type)، ونوع المعلم يُطابَق من نص حر ("School
     * Teacher ( K - 12 )") لا قيمة نظيفة جاهزة. حقول بلا عمود مخصَّص (الجنسية،
     * المواد، المنهج) تُجمَع في bio بدل فقدانها.
     */
    public function test_processing_a_batch_maps_rich_external_form_headers(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = $this->createAdmin()[0];

        $csv = <<<'CSV'
        Full Name ( Arabic ),Full Name ( English ),Phone Number,Whatsapp Number,Email Address,Gender,Type Of Teaching,Nationality,Subject(s) you teach,Curriculum Type
        معلمة الكيمياء,Rich Form Teacher,0500000010,00500000010,rich.form.teacher.import@example.com,Female,School Teacher ( K - 12 ),Syrian,"Chemistry, Biology","MOE, IG"
        CSV;

        $batch = $this->storeBatch($admin, $csv);

        app(TeacherImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(0, $batch->failed_count);

        $user = User::where('email', 'rich.form.teacher.import@example.com')->firstOrFail();
        $this->assertSame('معلمة الكيمياء', $user->name);
        $this->assertSame('00500000010', $user->whatsapp);
        $this->assertSame('female', $user->gender);

        $teacher = $user->teacher;
        $this->assertSame('school', $teacher->teacher_type);
        $this->assertStringContainsString('الجنسية: Syrian', $teacher->bio);
        $this->assertStringContainsString('المواد التي يدرّسها: Chemistry, Biology', $teacher->bio);
        $this->assertStringContainsString('نوع المنهج: MOE, IG', $teacher->bio);
    }

    public function test_processing_marks_the_batch_failed_when_row_count_exceeds_max_setting(): void
    {
        Storage::fake('local');
        $this->seed(SettingsSeeder::class);
        app(SettingsService::class)->set('teacher_import_max_rows', '1');

        $admin = $this->createAdmin()[0];

        $csv = "name,email,teacher_type\nمعلم1,t1.import@example.com,school\nمعلم2,t2.import@example.com,school";
        $batch = $this->storeBatch($admin, $csv);

        app(TeacherImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->failure_reason);
        $this->assertDatabaseMissing('users', ['email' => 't1.import@example.com']);
    }

    public function test_admin_can_list_and_view_import_batches(): void
    {
        [$admin, $adminToken] = $this->createAdmin();

        $batch = ImportBatch::create([
            'type' => 'teacher',
            'status' => 'completed',
            'file_name' => 'teachers.xlsx',
            'file_path' => 'imports/teachers/fake.xlsx',
            'total_rows' => 5,
            'processed_rows' => 5,
            'imported_count' => 5,
            'failed_count' => 0,
            'created_by' => $admin->id,
        ]);

        $list = $this->as($adminToken)->getJson('/api/import-batches?type=teacher');
        $list->assertStatus(200)->assertJsonPath('data.0.id', $batch->id);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    private function storeBatch(User $admin, string $csv): ImportBatch
    {
        $file = UploadedFile::fake()->createWithContent('teachers.csv', $csv);
        $path = $file->store('imports/teachers', 'local');

        return ImportBatch::create([
            'type' => 'teacher',
            'status' => 'queued',
            'file_name' => 'teachers.csv',
            'file_path' => $path,
            'created_by' => $admin->id,
        ]);
    }
}
