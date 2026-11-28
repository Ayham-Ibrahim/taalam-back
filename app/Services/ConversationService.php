<?php

namespace App\Services;

use App\Events\Chat\ChatUpdated;
use App\Events\Chat\ConversationStatusChanged;
use App\Events\Chat\MessageSent;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\SupportPackagePurchase;
use App\Models\User;
use App\Traits\BroadcastsAfterCommit;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * دورة حياة المحادثة: تُفتح عند أول تأكيد (حجز فردي/يدوي، تسجيل كورس، باقة دعم) ولا تُغلق
 * أبداً. كل تأكيد لاحق يضيف رسالة نظام فقط، ولا يرفع حظراً إدارياً قائماً.
 * openFor* تُستدعى من خدمات الحجز بعد حفظ معاملتها (DB::afterCommit)، وأي فشل هنا
 * يُسجَّل ولا يُفشل التأكيد نفسه — الدفع/الحجز أهم من رسالة النظام.
 */
class ConversationService
{
    use BroadcastsAfterCommit, LogsAuditEvents;

    public function openForBooking(Booking $booking): void
    {
        $this->openAfterCommit(function () use ($booking) {
            $booking->loadMissing(['student.user', 'teacher.user', 'package:id,title']);

            $this->openFor($booking->student?->user, $booking->teacher?->user, 'تم تأكيد حجز جديد: '.$booking->package?->title, [
                'event' => 'booking_confirmed',
                'booking_id' => $booking->id,
            ]);
        });
    }

    public function openForEnrollment(Enrollment $enrollment): void
    {
        $this->openAfterCommit(function () use ($enrollment) {
            $enrollment->loadMissing(['student.user', 'course.teacher.user']);

            $this->openFor($enrollment->student?->user, $enrollment->course?->teacher?->user, 'تم التسجيل في كورس: '.$enrollment->course?->title, [
                'event' => 'enrollment_confirmed',
                'enrollment_id' => $enrollment->id,
                'course_id' => $enrollment->course_id,
            ]);
        });
    }

    public function openForSupportPurchase(SupportPackagePurchase $purchase): void
    {
        $this->openAfterCommit(function () use ($purchase) {
            $purchase->loadMissing(['student.user', 'academicSupporter.user', 'supportPackage:id,title']);

            $this->openFor($purchase->student?->user, $purchase->academicSupporter?->user, 'تم شراء باقة دعم أكاديمي: '.$purchase->supportPackage?->title, [
                'event' => 'support_purchase_confirmed',
                'support_package_purchase_id' => $purchase->id,
            ]);
        });
    }

    /**
     * $systemText = null: إنشاء صامت بلا رسالة نظام (أمر backfill للعلاقات السابقة للإطلاق).
     * createOrFirst يحسم سباق تأكيدين متزامنين لنفس الزوج عبر القيد الفريد.
     */
    public function openFor(?User $studentUser, ?User $providerUser, ?string $systemText = null, array $meta = []): ?Conversation
    {
        if (! $studentUser || ! $providerUser || $studentUser->id === $providerUser->id) {
            return null;
        }

        $conversation = Conversation::createOrFirst(
            ['student_user_id' => $studentUser->id, 'provider_user_id' => $providerUser->id],
            ['status' => Conversation::STATUS_ACTIVE],
        );

        if ($systemText !== null) {
            $this->postSystemMessage($conversation, $systemText, $meta);
        }

        return $conversation;
    }

    /**
     * لا تُنشئ محادثة — المرفق حدث داخل علاقة قائمة أصلاً. الرافع والمستلم قد يكونان
     * بأي ترتيب (طالب يرفع لمعلمه أو العكس).
     */
    public function postSystemMessageBetween(int $firstUserId, int $secondUserId, string $text, array $meta = []): void
    {
        $conversation = Conversation::between($firstUserId, $secondUserId)->first();

        if ($conversation) {
            $this->postSystemMessage($conversation, $text, $meta);
        }
    }

    public function postSystemMessage(Conversation $conversation, string $text, array $meta = []): Message
    {
        $message = $conversation->messages()->create([
            'sender_id' => null,
            'type' => Message::TYPE_SYSTEM,
            'body' => $text,
            'meta' => $meta ?: null,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        // عبر الطابور: قد تتكرر لعشرات المحادثات في طلب واحد (مرفق جلسة جماعية)
        $this->broadcastAfterCommit(new MessageSent($message), toOthers: false, queued: true);

        $payload = MessageResource::preview($message);
        foreach ([$conversation->student_user_id, $conversation->provider_user_id] as $userId) {
            $this->broadcastAfterCommit(new ChatUpdated($userId, $conversation->id, $this->unreadTotal($userId), $payload), toOthers: false, queued: true);
        }

        return $message;
    }

    public function block(Conversation $conversation, User $admin, ?Complaint $complaint = null): Conversation
    {
        if (! $conversation->isActive()) {
            throw ValidationException::withMessages(['status' => ['المحادثة محظورة بالفعل']]);
        }

        $conversation->update([
            'status' => Conversation::STATUS_BLOCKED,
            'blocked_by' => $admin->id,
            'blocked_at' => now(),
        ]);

        $this->audit('conversation.blocked', $conversation, ['status' => Conversation::STATUS_ACTIVE], [
            'status' => Conversation::STATUS_BLOCKED,
            'complaint_id' => $complaint?->id,
        ], null, $admin->id);

        $this->broadcastAfterCommit(new ConversationStatusChanged($conversation), toOthers: false);

        return $conversation->fresh();
    }

    public function unblock(Conversation $conversation, User $admin, ?Complaint $complaint = null): Conversation
    {
        if ($conversation->isActive()) {
            throw ValidationException::withMessages(['status' => ['المحادثة غير محظورة']]);
        }

        $conversation->update([
            'status' => Conversation::STATUS_ACTIVE,
            'blocked_by' => null,
            'blocked_at' => null,
        ]);

        $this->audit('conversation.unblocked', $conversation, ['status' => Conversation::STATUS_BLOCKED], [
            'status' => Conversation::STATUS_ACTIVE,
            'complaint_id' => $complaint?->id,
        ], null, $admin->id);

        $this->broadcastAfterCommit(new ConversationStatusChanged($conversation), toOthers: false);

        return $conversation->fresh();
    }

    public function unreadTotal(int $userId): int
    {
        return Message::query()
            ->whereIn('conversation_id', Conversation::forUser($userId)->select('id'))
            ->where('type', Message::TYPE_TEXT)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }

    private function openAfterCommit(callable $open): void
    {
        DB::afterCommit(function () use ($open) {
            try {
                $open();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
