<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * وثائق التوثيق (هوية/شهادات/خبرة...) تُقبَل كصورة أو PDF فقط، وبحجم 5 ميغابايت
 * كحد أقصى — لا HTML ولا فيديو ولا سكربتات ولا أي نوع ملف آخر (RULE).
 */
class UploadVerificationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_document_up_to_5mb_is_accepted(): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->create('identity.pdf', 5120, 'application/pdf'),
        ]);

        $response->assertStatus(201);
    }

    public function test_image_document_is_accepted(): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->image('identity.jpg', 800, 600),
        ]);

        $response->assertStatus(201);
    }

    public function test_document_over_5mb_is_rejected(): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->create('identity.pdf', 5121, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[DataProvider('disallowedFileProvider')]
    public function test_disallowed_file_types_are_rejected(string $name, string $mime): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->create($name, 200, $mime),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('verification_documents', 0);
    }

    public static function disallowedFileProvider(): array
    {
        return [
            'html' => ['malicious.html', 'text/html'],
            'video' => ['video.mp4', 'video/mp4'],
            'script' => ['script.js', 'application/javascript'],
            'office doc' => ['doc.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
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
