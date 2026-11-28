<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\FindConversationRequest;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\PresenceService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly MessageService $messageService,
        private readonly PresenceService $presence,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Conversation::class);

        $user = $request->user();

        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $conversations = Conversation::query()
            ->forUser($user)
            ->searchCounterpartName($user->id, trim((string) $request->input('search')))
            ->withUnreadCountFor($user->id)
            ->with(['studentUser', 'providerUser', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 30), 100));

        $conversations->setCollection(
            $conversations->getCollection()->map(fn (Conversation $c) => ConversationResource::make($c)->resolve($request))
        );

        return $this->paginate($conversations);
    }

    /** لا يُنشئ محادثة إطلاقاً — المحادثة تُفتح فقط عند تأكيد حجز/تسجيل/باقة دعم */
    public function store(FindConversationRequest $request)
    {
        $conversation = Conversation::between($request->user()->id, $request->integer('user_id'))->first();

        if (! $conversation) {
            return $this->error('لا توجد محادثة مع هذا المستخدم — تُفتح المحادثة بعد تأكيد حجز أو تسجيل أو باقة دعم بينكما', 404);
        }

        return $this->show($request, $conversation);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $conversation = Conversation::query()
            ->whereKey($conversation->id)
            ->withUnreadCountFor($request->user()->id)
            ->with(['studentUser', 'providerUser', 'latestMessage'])
            ->first();

        return $this->success(ConversationResource::make($conversation)->resolve($request));
    }

    /**
     * هل الطرف الآخر متصل بالمنصة الآن (أي صفحة)؟ مسار منفصل عن show كي لا يبطئ سؤالُ Reverb
     * فتح المحادثة؛ الفرونت يطلبه فقط والـ WebSocket متصل، كل دقيقة والتبويب ظاهر.
     * "في المحادثة الآن" لا يمر من هنا — تعرفه قناة presence المحادثة مباشرة.
     */
    public function presence(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return $this->success([
            'online' => $this->presence->isOnline($conversation->otherParticipantId($request->user())),
        ]);
    }

    public function markRead(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return $this->success($this->messageService->markRead($conversation, $request->user()));
    }

    public function unreadCount(Request $request)
    {
        $this->authorize('viewAny', Conversation::class);

        return $this->success(['unread_count' => $this->conversationService->unreadTotal($request->user()->id)]);
    }
}
