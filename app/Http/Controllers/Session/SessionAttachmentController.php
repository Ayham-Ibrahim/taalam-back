<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\UploadSessionAttachmentRequest;
use App\Http\Resources\Session\SessionAttachmentResource;
use App\Models\ClassSession;
use App\Models\SessionAttachment;
use App\Services\FileStorage;
use App\Services\SessionAttachmentService;
use Illuminate\Http\Request;

class SessionAttachmentController extends Controller
{
    public function __construct(private readonly SessionAttachmentService $attachmentService) {}

    public function index(Request $request, ClassSession $session)
    {
        $this->authorize('viewAny', [SessionAttachment::class, $session]);

        $attachments = $session->attachments()
            ->visibleTo($request->user(), $session)
            ->with('uploader:id,name')
            ->latest()
            ->get();

        return $this->success(SessionAttachmentResource::collection($attachments));
    }

    public function store(UploadSessionAttachmentRequest $request, ClassSession $session)
    {
        $attachment = $this->attachmentService->upload(
            $session,
            $request->user(),
            $request->file('file'),
            $request->validated('title'),
        );

        return $this->success(new SessionAttachmentResource($attachment), 'تم رفع الملف بنجاح', 201);
    }

    public function destroy(SessionAttachment $attachment)
    {
        $this->authorize('delete', $attachment);

        $this->attachmentService->delete($attachment);

        return $this->success(null, 'تم حذف الملف');
    }

    /** خلف توقيع مؤقت فقط (لا auth:sanctum) — يُفتح في تبويب جديد من رابط download_url. */
    public function download(SessionAttachment $attachment)
    {
        return FileStorage::view(SessionAttachment::DISK, $attachment->path, $attachment->original_name);
    }
}
