<?php

namespace Tests\Feature\Chat;

use App\Http\Resources\Chat\MessageResource;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * المحادثة: تُفتح عند التأكيد ولا تُغلق، الطرفان وحدهما يكتبان، تعديل/حذف خلال 30 دقيقة،
 * فلترة وسائل التواصل، واطلاع الأدمن من الشكوى المرتبطة فقط.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_booking_opens_one_conversation_and_later_bookings_only_add_system_messages(): void
    {
        [$studentUser, $teacher, $package, $student] = $this->createRelation();

        app(BookingService::class)->confirmBooking($this->pendingBooking($student, $teacher, $package));
        app(BookingService::class)->confirmBooking($this->pendingBooking($student, $teacher, $package));

        $this->assertSame(1, Conversation::count());
        $conversation = Conversation::first();
        $this->assertSame($studentUser->id, (int) $conversation->student_user_id);
        $this->assertSame($teacher->user_id, (int) $conversation->provider_user_id);
        $this->assertSame(2, $conversation->messages()->where('type', 'system')->count());
    }

    public function test_only_participants_can_read_and_send(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();

        $this->as($this->token($studentUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'مرحباً أستاذ'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'مرحباً أستاذ');

        $this->as($this->token($teacherUser))
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');

        $outsider = User::factory()->student()->create();
        $this->as($this->token($outsider))->getJson("/api/conversations/{$conversation->id}/messages")->assertForbidden();
        $this->as($this->token($outsider))->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'x'])->assertForbidden();

        // الأدمن لا يقرأ المحادثة مباشرة — من الشكوى فقط
        $admin = User::factory()->admin()->create();
        $this->as($this->token($admin))->getJson("/api/conversations/{$conversation->id}/messages")->assertForbidden();
    }

    public function test_contact_info_is_rejected_in_send_and_edit(): void
    {
        [$conversation, $studentUser] = $this->createConversation();
        $token = $this->token($studentUser);

        foreach ([
            'رقمي ٠٩٣٣ ١٢٣ ٤٥٦',
            'call me 0933-123-456',
            '+963 933 123456',
            'راسلني على ali@example.com',
            'https://example.org/page',
            'www.something.net',
            'تواصل wa.me/963933',
        ] as $body) {
            $this->as($token)
                ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => $body])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['body']);
        }

        foreach ([
            'حللت التمرين 12 صفحة 120',
            'أرسلت الملف main.py و notes.pdf',
            'الموعد الساعة 10:30',
            'الامتحان يوم 28/11/2026',
            'العام الدراسي 2024/2025 والفصل 2024-2025',
            'الموعد 2026-12-01',
        ] as $body) {
            $this->as($token)->postJson("/api/conversations/{$conversation->id}/messages", ['body' => $body])->assertCreated();
        }

        $message = Message::where('type', 'text')->latest('id')->first();
        $this->as($token)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'رقمي 0933123456'])
            ->assertStatus(422);
    }

    public function test_edit_and_delete_within_thirty_minutes_keep_the_original_for_admin(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $studentToken = $this->token($studentUser);

        $id = $this->as($studentToken)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'النص الأصلي'])
            ->json('data.id');

        // الطرف الآخر لا يعدّل رسالة غيره
        $this->as($this->token($teacherUser))->patchJson("/api/messages/{$id}", ['body' => 'تلاعب'])->assertForbidden();

        $this->as($studentToken)
            ->patchJson("/api/messages/{$id}", ['body' => 'النص المعدّل'])
            ->assertOk()
            ->assertJsonPath('data.is_edited', true);
        $this->assertDatabaseHas('message_edits', ['message_id' => $id, 'old_body' => 'النص الأصلي']);

        $this->as($studentToken)->deleteJson("/api/messages/{$id}")->assertOk();
        $this->assertSoftDeleted('messages', ['id' => $id, 'body' => 'النص المعدّل']);

        $this->as($this->token($teacherUser))
            ->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertJsonPath('data.messages.0.is_deleted', true)
            ->assertJsonPath('data.messages.0.body', null);

        // بعد المهلة: لا تعديل ولا حذف — برسالة واضحة لا 403 عام
        $old = $conversation->messages()->create(['sender_id' => $studentUser->id, 'type' => 'text', 'body' => 'قديمة']);
        $old->forceFill(['created_at' => now()->subMinutes(31)])->save();

        $this->as($studentToken)->patchJson("/api/messages/{$old->id}", ['body' => 'تعديل متأخر'])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'انتهت مهلة تعديل الرسالة (30 دقيقة من إرسالها)');
        $this->as($studentToken)->deleteJson("/api/messages/{$old->id}")->assertStatus(422);
        $this->assertSame('قديمة', $old->fresh()->body);
    }

    public function test_unread_count_and_mark_read(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $teacherToken = $this->token($teacherUser);

        $this->as($this->token($studentUser))->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'سؤال أول']);
        $this->as($this->token($studentUser))->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'سؤال ثانٍ']);

        $this->as($teacherToken)->getJson('/api/chat/unread-count')->assertJsonPath('data.unread_count', 2);
        $this->as($teacherToken)->getJson('/api/conversations')->assertJsonPath('data.0.unread_count', 2);

        $this->as($teacherToken)->postJson("/api/conversations/{$conversation->id}/read")
            ->assertJsonPath('data.marked', 2)
            ->assertJsonStructure(['data' => ['marked', 'read_at']]);
        $this->as($teacherToken)->getJson('/api/chat/unread-count')->assertJsonPath('data.unread_count', 0);

        // المرسل نفسه لا يرى رسائله غير مقروءة
        $this->as($this->token($studentUser))->getJson('/api/chat/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_conversation_list_shows_a_deleted_last_message_as_deleted(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $token = $this->token($studentUser);

        $this->as($token)->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'الأولى']);
        $id = $this->as($token)->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'الثانية'])->json('data.id');
        $this->as($token)->deleteJson("/api/messages/{$id}")->assertOk();

        $this->as($this->token($teacherUser))->getJson('/api/conversations')
            ->assertJsonPath('data.0.last_message.id', $id)
            ->assertJsonPath('data.0.last_message.is_deleted', true)
            ->assertJsonPath('data.0.last_message.body', null);
    }

    public function test_websocket_preview_of_a_long_message_stays_small(): void
    {
        [$conversation, $studentUser] = $this->createConversation();
        $message = $conversation->messages()->create(['sender_id' => $studentUser->id, 'type' => 'text', 'body' => str_repeat('ع', 2000)]);

        $preview = MessageResource::preview($message);

        $this->assertLessThanOrEqual(123, mb_strlen($preview['body']));
        $this->assertSame($message->id, $preview['id']);
        // الحد الأقصى للرسالة كاملةً داخل حد طلب البث في Reverb (config/reverb.php)
        $fullPayload = json_encode(['data' => json_encode(['message' => MessageResource::make($message)->resolve()])]);
        $this->assertLessThan((int) config('reverb.servers.reverb.max_request_size'), strlen($fullPayload));
    }

    public function test_guest_trial_account_cannot_be_messaged_until_it_registers(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $studentUser->update(['account_type' => User::ACCOUNT_GUEST]);

        $this->as($this->token($teacherUser))->getJson("/api/conversations/{$conversation->id}")
            ->assertJsonPath('data.counterpart.is_available', false);
        $this->as($this->token($teacherUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'مرحباً'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        // بعد تسجيله يتحوّل نفس الحساب وتبقى المحادثة
        $studentUser->update(['account_type' => User::ACCOUNT_REGULAR]);
        $this->as($this->token($teacherUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'مرحباً'])
            ->assertCreated();
    }

    public function test_presence_is_visible_to_participants_only(): void
    {
        [$conversation, $studentUser] = $this->createConversation();

        // null broadcaster في الاختبارات: غير متصل دائماً
        $this->as($this->token($studentUser))->getJson("/api/conversations/{$conversation->id}/presence")
            ->assertOk()
            ->assertJsonPath('data.online', false);
        $this->as($this->token(User::factory()->student()->create()))
            ->getJson("/api/conversations/{$conversation->id}/presence")
            ->assertForbidden();
    }

    public function test_search_matches_the_counterpart_name_only(): void
    {
        [$first, $studentUser, $teacherUser] = $this->createConversation();
        $teacherUser->update(['name' => 'أحمد الرياضيات']);
        $otherTeacher = User::factory()->teacher()->create(['name' => 'سلمى الفيزياء']);
        $second = app(ConversationService::class)->openFor($studentUser, $otherTeacher);
        $studentUser->update(['name' => 'أحمد الطالب']);
        $token = $this->token($studentUser);

        $this->as($token)->getJson('/api/conversations?search=سلمى')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);

        // "أحمد" اسم الطالب نفسه أيضاً — يطابق المعلم أحمد فقط لا كل محادثات الطالب
        $this->as($token)->getJson('/api/conversations?search=أحمد')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);

        $this->as($token)->getJson('/api/conversations?search=%')->assertJsonCount(0, 'data');
        $this->as($token)->getJson('/api/conversations')->assertJsonCount(2, 'data');
    }

    public function test_find_conversation_never_creates_one(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();

        $this->as($this->token($studentUser))
            ->postJson('/api/conversations', ['user_id' => $teacherUser->id])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.counterpart.id', $teacherUser->id);

        $stranger = User::factory()->teacher()->create();
        $this->as($this->token($studentUser))->postJson('/api/conversations', ['user_id' => $stranger->id])->assertNotFound();
        $this->assertSame(1, Conversation::count());
    }

    public function test_report_links_complaint_and_admin_views_everything_with_audit_then_blocks(): void
    {
        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $teacherToken = $this->token($teacherUser);

        $id = $this->as($teacherToken)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'رسالة مسيئة'])
            ->json('data.id');
        $this->as($teacherToken)->deleteJson("/api/messages/{$id}")->assertOk();

        // المرسل لا يبلّغ عن رسالته، والطرف الآخر يبلّغ حتى بعد حذفها
        $this->as($teacherToken)->postJson("/api/messages/{$id}/report")->assertForbidden();
        $complaintId = $this->as($this->token($studentUser))
            ->postJson("/api/messages/{$id}/report", ['description' => 'إساءة'])
            ->assertCreated()
            ->json('data.complaint_id');

        // تكرار الإبلاغ عن نفس الرسالة: نفس الشكوى، بلا شكوى جديدة
        $this->as($this->token($studentUser))
            ->postJson("/api/messages/{$id}/report")
            ->assertCreated()
            ->assertJsonPath('data.complaint_id', $complaintId);
        $this->assertSame(1, Complaint::where('reported_message_id', $id)->count());

        $this->assertDatabaseHas('complaints', [
            'id' => $complaintId,
            'category' => 'chat_report',
            'conversation_id' => $conversation->id,
            'reported_message_id' => $id,
            'filed_by_user_id' => $studentUser->id,
        ]);

        $admin = User::factory()->admin()->create();
        $adminToken = $this->token($admin);

        $this->as($adminToken)
            ->getJson("/api/admin/complaints/{$complaintId}/conversation")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'رسالة مسيئة')
            ->assertJsonPath('data.reported_message_id', $id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.viewed_by_admin']);

        // شكوى بلا conversation_id لا تفتح أي محادثة
        $plain = Complaint::create([
            'reference' => 'CM-PLAIN', 'student_id' => Student::where('user_id', $studentUser->id)->value('id'),
            'category' => 'other', 'description' => 'عامة', 'status' => 'open',
        ]);
        $this->as($adminToken)->getJson("/api/admin/complaints/{$plain->id}/conversation")->assertForbidden();

        // الحظر يوقف الإرسال والتعديل، وتأكيد جديد لا يرفعه
        $this->as($adminToken)->postJson("/api/admin/conversations/{$conversation->id}/block", ['complaint_id' => $complaintId])->assertOk();
        $this->as($this->token($studentUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'هل تسمعني؟'])
            ->assertStatus(422);

        app(ConversationService::class)->openFor($studentUser, $teacherUser, 'تم تأكيد حجز جديد');
        $this->assertSame('blocked', $conversation->fresh()->status);

        $this->as($adminToken)->postJson("/api/admin/conversations/{$conversation->id}/unblock")->assertOk();
        $this->as($this->token($studentUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'عدنا'])
            ->assertCreated();

        // غير الأدمن لا يحظر
        $this->as($teacherToken)->postJson("/api/admin/conversations/{$conversation->id}/block")->assertForbidden();
    }

    public function test_independent_supporter_without_teacher_record_can_chat_and_report(): void
    {
        $studentUser = User::factory()->student()->create();
        Student::create(['user_id' => $studentUser->id, 'education_type' => 'university']);
        $supporterUser = User::factory()->create(['role' => 'academic_support']);

        $conversation = app(ConversationService::class)->openFor($studentUser, $supporterUser, 'تم شراء باقة دعم أكاديمي');

        $id = $this->as($this->token($studentUser))
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'أحتاج مساعدة في الواجب'])
            ->assertCreated()
            ->json('data.id');

        $this->as($this->token($supporterUser))->getJson('/api/conversations')->assertJsonPath('data.0.id', $conversation->id);

        $complaintId = $this->as($this->token($supporterUser))
            ->postJson("/api/messages/{$id}/report")
            ->assertCreated()
            ->json('data.complaint_id');

        $this->assertDatabaseHas('complaints', ['id' => $complaintId, 'student_id' => null, 'teacher_id' => null, 'filed_by_user_id' => $supporterUser->id]);
        $this->as($this->token($supporterUser))->getJson("/api/complaints/{$complaintId}")->assertOk();
    }

    public function test_backfill_creates_conversations_for_previously_confirmed_relations_once(): void
    {
        [$studentUser, $teacher, $package, $student] = $this->createRelation();
        $booking = $this->pendingBooking($student, $teacher, $package);
        $booking->forceFill(['status' => 'completed', 'confirmed_at' => now()->subYear()])->save();
        $this->pendingBooking($student, Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']), $package);

        $this->artisan('chat:backfill-conversations')->assertSuccessful();
        $this->artisan('chat:backfill-conversations')->assertSuccessful();

        $this->assertSame(1, Conversation::count());
        $this->assertDatabaseHas('conversations', ['student_user_id' => $studentUser->id, 'provider_user_id' => $teacher->user_id]);
        $this->assertSame(0, Message::count());
    }

    public function test_channel_auth_admits_only_the_two_participants(): void
    {
        // الاختبارات تعمل بـ null broadcaster — مشغّل reverb حقيقي (توقيع محلي بلا اتصال شبكة)
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        [$conversation, $studentUser, $teacherUser] = $this->createConversation();
        $payload = ['socket_id' => '1234.5678', 'channel_name' => "presence-conversation.{$conversation->id}"];

        $this->as($this->token($studentUser))->postJson('/api/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);
        $this->as($this->token($teacherUser))->postJson('/api/broadcasting/auth', $payload)->assertOk();

        $this->as($this->token(User::factory()->admin()->create()))->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
        $this->as($this->token(User::factory()->student()->create()))->postJson('/api/broadcasting/auth', $payload)->assertForbidden();

        $this->as($this->token($studentUser))
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-user.{$studentUser->id}"])
            ->assertOk();
        $this->as($this->token($studentUser))
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-user.{$teacherUser->id}"])
            ->assertForbidden();
    }

    /** @return array{0: Conversation, 1: User, 2: User} */
    private function createConversation(): array
    {
        [$studentUser, $teacher] = $this->createRelation();
        $conversation = app(ConversationService::class)->openFor($studentUser, $teacher->user);

        return [$conversation, $studentUser, $teacher->user];
    }

    private function createRelation(): array
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة رياضيات',
            'subject_id' => Subject::create(['code' => 'chat-'.uniqid(), 'name_ar' => 'رياضيات'])->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 250,
            'platform_revenue' => 150,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        return [$studentUser, $teacher, $package, $student];
    }

    private function pendingBooking(Student $student, Teacher $teacher, Package $package): Booking
    {
        return Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 250,
            'teacher_amount' => 100,
            'platform_amount' => 150,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'pending_payment',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }
}
