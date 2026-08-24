<?php

namespace Tests\Feature\Student;

use App\Jobs\ProcessStudentImportJob;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StudentImportService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الاستيراد أصبح غير متزامن (ProcessStudentImportJob عبر الطابور) لا معالجة
 * مباشرة داخل الطلب — ملفات بآلاف الصفوف كانت تتجاوز مهلة المتصفح رغم
 * اكتمالها فعلياً في الخلفية. اختبارات endpoint الاستيراد تتحقق فقط من دفع
 * الوظيفة الصحيحة وإنشاء سجل ImportBatch (Queue::fake يمنع تنفيذها الفعلي)؛
 * صحة معالجة الصفوف نفسها (StudentImportService::processBatch) تُختبَر مباشرة
 * بمعزل عن الطابور — أبسط وأسرع، ونفس المنطق تماماً الذي ينفّذه Job فعلياً.
 */
class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_import_request_queues_a_job_and_returns_a_queued_batch(): void
    {
        Queue::fake();
        // Queue::fake يمنع تنفيذ الوظيفة (فلا يُحذَف الملف المخزَّن تلقائياً في
        // finally الخاص بها) — بلا هذا، يترك كل تشغيل لهذا الاختبار ملفاً حقيقياً
        // يتيماً على القرص الفعلي storage/app/private/imports.
        Storage::fake('local');

        [, $adminToken] = $this->createAdmin();

        $csv = "name,email\nأحمد علي,ahmad.import@example.com";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($adminToken)->post('/api/students/import', ['file' => $file]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', 'student')
            ->assertJsonPath('data.status', 'queued');

        $batchId = $response->json('data.id');
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'type' => 'student', 'status' => 'queued']);

        Queue::assertPushed(ProcessStudentImportJob::class, fn ($job) => $job->importBatchId === $batchId);
    }

    public function test_non_admin_cannot_import_students(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $csv = "name,email\nطالب,x.import@example.com";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->as($token)->post('/api/students/import', ['file' => $file]);

        $response->assertStatus(403);
    }

    public function test_processing_a_batch_imports_valid_students_and_reports_per_row_errors(): void
    {
        Notification::fake();
        Storage::fake('local');

        $existing = User::factory()->student()->create(['email' => 'duplicate@example.com']);
        $admin = $this->createAdmin()[0];

        // الاسم والإيميل فقط إلزاميان الآن — صف بلا اسم وصف ببريد مكرر هما
        // الحالتان الوحيدتان اللتان يجب أن تُرفضا، لا نقص بيانات أكاديمية.
        $csv = <<<'CSV'
        name,email,phone
        طالب صحيح,valid.import@example.com,0500000003
        ,missing.name@example.com,0500000004
        طالب مكرر,duplicate@example.com,0500000005
        CSV;

        $batch = $this->storeBatch($admin, $csv);

        app(StudentImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(2, $batch->failed_count);
        $this->assertCount(2, $batch->errors);
        $this->assertSame(3, $batch->errors[0]['row']); // الصف الثاني (بيانات) = الصف 3 في الملف
        $this->assertSame(4, $batch->errors[1]['row']);

        $this->assertDatabaseHas('users', ['email' => 'valid.import@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'missing.name@example.com']);
    }

    /**
     * الحالة الحقيقية التي دفعت لهذا التعديل: ملفات استيراد فعلية (قوائم
     * شركاء خارجيين) لا تحمل أي تصنيف أكاديمي إطلاقاً — فقط اسم، إيميل،
     * وأحياناً هاتف/مدينة/بلد. كانت تُرفَض جميعها سابقاً بسبب education_type
     * الإلزامي، رغم أن الاسم والإيميل (الشرط الوحيد الفعلي) متوفران تماماً.
     */
    public function test_a_row_with_only_name_and_email_and_optional_contact_fields_imports_successfully(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = $this->createAdmin()[0];

        $csv = <<<'CSV'
        email,name,phone1,city,country
        noeducation.import@example.com,طالب بلا تصنيف أكاديمي,971500000099,Emirate,AE
        CSV;

        $batch = $this->storeBatch($admin, $csv);

        app(StudentImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(0, $batch->failed_count);

        $this->assertDatabaseHas('users', [
            'email' => 'noeducation.import@example.com',
            'phone' => '971500000099',
        ]);
        $this->assertDatabaseHas('students', [
            'education_type' => null,
            'city' => 'Emirate',
            'country' => 'AE',
        ]);
    }

    public function test_processing_marks_the_batch_failed_when_row_count_exceeds_max_setting(): void
    {
        Storage::fake('local');
        $this->seed(SettingsSeeder::class);
        app(SettingsService::class)->set('student_import_max_rows', '1');

        $admin = $this->createAdmin()[0];

        $csv = "name,email\nطالب1,s1.import@example.com\nطالب2,s2.import@example.com";
        $batch = $this->storeBatch($admin, $csv);

        app(StudentImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->failure_reason);
        $this->assertDatabaseMissing('users', ['email' => 's1.import@example.com']);
    }

    public function test_admin_can_list_and_view_import_batches(): void
    {
        [$admin, $adminToken] = $this->createAdmin();

        $batch = ImportBatch::create([
            'type' => 'student',
            'status' => 'completed',
            'file_name' => 'students.xlsx',
            'file_path' => 'imports/students/fake.xlsx',
            'total_rows' => 10,
            'processed_rows' => 10,
            'imported_count' => 8,
            'failed_count' => 2,
            'created_by' => $admin->id,
        ]);

        $list = $this->as($adminToken)->getJson('/api/import-batches');
        $list->assertStatus(200)->assertJsonPath('data.0.id', $batch->id);

        $show = $this->as($adminToken)->getJson("/api/import-batches/{$batch->id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percent', 100)
            ->assertJsonPath('data.created_by', $admin->name);
    }

    public function test_non_admin_cannot_view_import_batches(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/import-batches');

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

    private function storeBatch(User $admin, string $csv): ImportBatch
    {
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);
        $path = $file->store('imports/students', 'local');

        return ImportBatch::create([
            'type' => 'student',
            'status' => 'queued',
            'file_name' => 'students.csv',
            'file_path' => $path,
            'created_by' => $admin->id,
        ]);
    }
}
