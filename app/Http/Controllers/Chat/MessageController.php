<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ReportMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ComplaintService;
use App\Services\MessageService;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly ComplaintService $complaintService,
    ) {}

    /**
     * cursor بالمعرّف لا ترقيم صفحات: before_id لتحميل الأقدم عند التمرير للأعلى، after_id
     * لجلب ما فات بعد انقطاع WebSocket. المحذوفة تُعاد (بلا نص) كي يظهر "تم حذف هذه الرسالة".
     */
    public function index(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'before_id' => ['nullable', 'integer'],
            'after_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = $request->integer('limit', 50);
        $afterId = $request->integer('after_id');

        $messages = $conversation->messages()
            ->withTrashed()
            ->when($request->filled('before_id'), fn ($q) => $q->where('id', '<', $request->integer('before_id')))
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId)->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit)->sortBy('id')->values();

        return $this->success([
            'messages' => MessageResource::collection($messages)->resolve($request),
            'has_more' => $hasMore,
        ]);
    }

    public function store(SendMessageRequest $request, Conversation $conversation)
    {
        $message = $this->messageService->send($conversation, $request->user(), $request->validated('body'));

        return $this->success(MessageResource::make($message)->resolve($request), 'تم إرسال الرسالة', 201);
    }

    public function update(UpdateMessageRequest $request, Message $message)
    {
        $message = $this->messageService->update($message, $request->validated('body'));

        return $this->success(MessageResource::make($message)->resolve($request), 'تم تعديل الرسالة');
    }

    public function destroy(Request $request, Message $message)
    {
        $this->authorize('delete', $message);

        $message = $this->messageService->delete($message);

        return $this->success(MessageResource::make($message)->resolve($request), 'تم حذف الرسالة');
    }

    public function report(ReportMessageRequest $request, Message $message)
    {
        $complaint = $this->complaintService->fileChatReport($message, $request->user(), $request->validated('description'));

        return $this->success(['complaint_id' => $complaint->id, 'reference' => $complaint->reference], 'تم إرسال الإبلاغ للإدارة', 201);
    }
}
