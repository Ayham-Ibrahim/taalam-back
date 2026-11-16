<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttachment;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionAttachmentUploaded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * مرفقات الجلسة: المعلم والطالب يتبادلان الملفات مباشرة من صفحة الجلسة بدل تمريرها
 * عبر موظف. ملف الطالب لا يظهر لبقية طلاب جلسة المجموعة.
 */
class SessionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_teacher_uploads_a_file_and_the_student_sees_it_and_is_notified(): void
    {
        [$teacherUser, $session, $studentUser] = $this->createSession();

        $this->as($this->token($teacherUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'title' => 'واجب الدرس الأول',
                'file' => UploadedFile::fake()->create('homework.pdf', 500, 'application/pdf'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.uploader_role', 'teacher')
            ->assertJsonPath('data.title', 'واجب الدرس الأول');

        $attachment = SessionAttachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->as($this->token($studentUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'homework.pdf')
            ->assertJsonPath('data.0.can_delete', false);

        Notification::assertSentTo($studentUser, SessionAttachmentUploaded::class);
        Notification::assertNotSentTo($teacherUser, SessionAttachmentUploaded::class);
    }

    public function test_student_uploads_a_file_and_only_the_teacher_is_notified(): void
    {
        [$teacherUser, $session, $studentUser] = $this->createSession();

        $this->as($this->token($studentUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create('solution.docx', 300, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.uploader_role', 'student')
            ->assertJsonPath('data.can_delete', true);

        $this->as($this->token($teacherUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Notification::assertSentTo($teacherUser, SessionAttachmentUploaded::class);
        Notification::assertNotSentTo($studentUser, SessionAttachmentUploaded::class);
    }

    public function test_a_students_file_is_hidden_from_other_students_in_a_group_session(): void
    {
        [$teacherUser, $session, $studentUser] = $this->createSession();
        $classmateUser = $this->addStudent($session, 'confirmed');

        $this->as($this->token($studentUser))->post("/api/class-sessions/{$session->id}/attachments", [
            'file' => UploadedFile::fake()->create('my-solution.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->as($this->token($teacherUser))->post("/api/class-sessions/{$session->id}/attachments", [
            'file' => UploadedFile::fake()->create('lesson.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $this->as($this->token($classmateUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'lesson.pdf');

        // إشعار واحد فقط — عن ملف المعلم، لا عن ملف زميله
        Notification::assertSentToTimes($classmateUser, SessionAttachmentUploaded::class, 1);
    }

    public function test_unrelated_student_cannot_list_or_upload(): void
    {
        [, $session] = $this->createSession();
        $strangerUser = User::factory()->student()->create();
        Student::create(['user_id' => $strangerUser->id, 'education_type' => 'school']);

        $this->as($this->token($strangerUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->assertForbidden();

        $this->as($this->token($strangerUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create('x.pdf', 100, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('session_attachments', 0);
    }

    public function test_student_with_unpaid_booking_can_see_but_not_upload(): void
    {
        [, $session] = $this->createSession();
        $unpaidUser = $this->addStudent($session, 'pending_payment');

        $this->as($this->token($unpaidUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->assertOk();

        $this->as($this->token($unpaidUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create('x.pdf', 100, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[DataProvider('disallowedFileProvider')]
    public function test_disallowed_file_types_are_rejected(string $name, string $mime): void
    {
        [$teacherUser, $session] = $this->createSession();

        $this->as($this->token($teacherUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create($name, 100, $mime),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('session_attachments', 0);
    }

    public static function disallowedFileProvider(): array
    {
        return [
            'html' => ['page.html', 'text/html'],
            'video' => ['video.mp4', 'video/mp4'],
            'php' => ['shell.php', 'application/x-php'],
            'zip' => ['archive.zip', 'application/zip'],
        ];
    }

    public function test_file_over_10mb_is_rejected(): void
    {
        [$teacherUser, $session] = $this->createSession();

        $this->as($this->token($teacherUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'حجم الملف أكبر من الحد المسموح (10 ميغابايت كحد أقصى)');
    }

    public function test_uploader_cannot_exceed_the_per_session_limit(): void
    {
        [$teacherUser, $session] = $this->createSession();

        foreach (range(1, SessionAttachment::MAX_PER_UPLOADER) as $i) {
            SessionAttachment::create([
                'class_session_id' => $session->id,
                'uploaded_by' => $teacherUser->id,
                'uploader_role' => 'teacher',
                'path' => "session_attachments/{$session->id}/f{$i}.pdf",
                'original_name' => "f{$i}.pdf",
            ]);
        }

        $this->as($this->token($teacherUser))
            ->post("/api/class-sessions/{$session->id}/attachments", [
                'file' => UploadedFile::fake()->create('one-more.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_only_the_uploader_or_admin_can_delete(): void
    {
        [$teacherUser, $session, $studentUser] = $this->createSession();

        $this->as($this->token($teacherUser))->post("/api/class-sessions/{$session->id}/attachments", [
            'file' => UploadedFile::fake()->create('lesson.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $attachment = SessionAttachment::firstOrFail();

        $this->as($this->token($studentUser))
            ->deleteJson("/api/session-attachments/{$attachment->id}")
            ->assertForbidden();

        $this->as($this->token($teacherUser))
            ->deleteJson("/api/session-attachments/{$attachment->id}")
            ->assertOk();

        $this->assertDatabaseCount('session_attachments', 0);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_admin_can_delete_any_file(): void
    {
        [, $session, $studentUser] = $this->createSession();
        $admin = User::factory()->admin()->create();

        $this->as($this->token($studentUser))->post("/api/class-sessions/{$session->id}/attachments", [
            'file' => UploadedFile::fake()->create('solution.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $attachment = SessionAttachment::firstOrFail();

        $this->as($this->token($admin))
            ->deleteJson("/api/session-attachments/{$attachment->id}")
            ->assertOk();

        $this->assertDatabaseCount('session_attachments', 0);
    }

    public function test_download_requires_a_valid_signature(): void
    {
        [$teacherUser, $session, $studentUser] = $this->createSession();

        $this->as($this->token($teacherUser))->post("/api/class-sessions/{$session->id}/attachments", [
            'file' => UploadedFile::fake()->create('lesson.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);
        $attachment = SessionAttachment::firstOrFail();

        $url = $this->as($this->token($studentUser))
            ->getJson("/api/class-sessions/{$session->id}/attachments")
            ->json('data.0.download_url');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->get($url)->assertOk();

        $this->get("/api/session-attachments/{$attachment->id}/download")->assertForbidden();
    }

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    /**
     * @return array{0: User, 1: ClassSession, 2: User}
     */
    private function createSession(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $subject = Subject::create(['code' => 'sa-'.uniqid(), 'name_ar' => 'مادة']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 5,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $session = ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->addDay(),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $studentUser = $this->addStudent($session, 'confirmed');

        return [$teacherUser, $session, $studentUser];
    }

    private function addStudent(ClassSession $session, string $bookingStatus): User
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $package = Package::where('teacher_id', $session->teacher_id)->firstOrFail();

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $session->teacher_id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => $bookingStatus,
        ]);

        if ($session->booking_id === null) {
            $session->update(['booking_id' => $booking->id]);
        }

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return $studentUser;
    }
}
