<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\AdminMessageResource;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Traits\LogsAuditEvents;
use Illuminate\Http\Request;

/**
 * اطلاع الأدمن على المحادثة حصراً من شكوى مرتبطة بها، وكل اطلاع يُسجَّل في AuditLog.
 * الأدمن لا يشترك في أي قناة WebSocket — يرى لقطة وقت الطلب فقط.
 */
class AdminConversationController extends Controller
{
    use LogsAuditEvents;

    public function __construct(private readonly ConversationService $conversationService) {}

    public function showFromComplaint(Request $request, Complaint $complaint)
    {
        $this->authorize('viewConversation', $complaint);

        $conversation = $complaint->conversation()
            ->with(['studentUser', 'providerUser', 'blocker:id,name'])
            ->firstOrFail();

        $messages = $conversation->messages()
            ->withTrashed()
            ->with('edits')
            ->orderBy('id')
            ->get();

        $this->audit('conversation.viewed_by_admin', $conversation, [], [
            'complaint_id' => $complaint->id,
        ], null, $request->user()->id);

        return $this->success([
            'conversation' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'student' => ConversationResource::participant($conversation->studentUser),
                'provider' => ConversationResource::participant($conversation->providerUser),
                'blocked_by' => $conversation->blocker?->only(['id', 'name']),
                'blocked_at' => $conversation->blocked_at,
                'created_at' => $conversation->created_at,
            ],
            'reported_message_id' => $complaint->reported_message_id,
            'messages' => AdminMessageResource::collection($messages)->resolve($request),
        ]);
    }

    public function block(Request $request, Conversation $conversation)
    {
        $this->authorize('block', Conversation::class);

        $conversation = $this->conversationService->block($conversation, $request->user(), $this->complaintFrom($request));

        return $this->success(['id' => $conversation->id, 'status' => $conversation->status], 'تم حظر المحادثة');
    }

    public function unblock(Request $request, Conversation $conversation)
    {
        $this->authorize('block', Conversation::class);

        $conversation = $this->conversationService->unblock($conversation, $request->user(), $this->complaintFrom($request));

        return $this->success(['id' => $conversation->id, 'status' => $conversation->status], 'تم رفع الحظر عن المحادثة');
    }

    /** complaint_id اختياري — للتدقيق فقط (من أي شكوى اتُّخذ القرار) */
    private function complaintFrom(Request $request): ?Complaint
    {
        $request->validate(['complaint_id' => ['nullable', 'integer', 'exists:complaints,id']]);

        return $request->filled('complaint_id') ? Complaint::find($request->integer('complaint_id')) : null;
    }
}
