<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Verification\RejectVerificationDocumentRequest;
use App\Http\Requests\Verification\UploadVerificationDocumentRequest;
use App\Models\Teacher;
use App\Models\VerificationDocument;
use App\Services\FileStorage;
use App\Services\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class VerificationDocumentController extends Controller
{
    public function __construct(private readonly VerificationService $verificationService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', VerificationDocument::class);

        $documents = VerificationDocument::query()
            ->with('teacher.user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($documents);
    }

    public function store(UploadVerificationDocumentRequest $request, Teacher $teacher)
    {
        $document = $this->verificationService->uploadDocument(
            $teacher,
            $request->file('file'),
            $request->validated('type'),
        );

        return $this->success($document, 'تم رفع الوثيقة بنجاح، بانتظار المراجعة', 201);
    }

    public function approve(Request $request, VerificationDocument $document)
    {
        $this->authorize('review', $document);

        $document = $this->verificationService->approveDocument($document, $request->user());

        return $this->success($document, 'تمت الموافقة على الوثيقة');
    }

    public function reject(RejectVerificationDocumentRequest $request, VerificationDocument $document)
    {
        $document = $this->verificationService->rejectDocument($document, $request->user(), $request->validated('reason'));

        return $this->success($document, 'تم رفض الوثيقة');
    }

    public function downloadUrl(VerificationDocument $document)
    {
        $this->authorize('view', $document);

        $url = URL::temporarySignedRoute(
            'verification-documents.download',
            now()->addMinutes(15),
            ['document' => $document->id],
        );

        return $this->success(['url' => $url, 'expires_in_minutes' => 15]);
    }

    public function download(VerificationDocument $document)
    {
        return FileStorage::view($document::DISK, $document->s3_path, $document->original_name);
    }
}
