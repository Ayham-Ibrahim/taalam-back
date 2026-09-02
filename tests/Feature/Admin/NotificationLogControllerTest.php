<?php

namespace Tests\Feature\Admin;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_notification_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->student()->create();

        NotificationLog::create([
            'user_id' => $recipient->id,
            'event' => 'student.imported',
            'channel' => 'email',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->as($admin->createToken('t')->plainTextToken)->getJson('/api/admin/notification-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.event', 'student.imported');
        $response->assertJsonPath('data.0.status', 'sent');
        $response->assertJsonPath('data.0.recipientEmail', $recipient->email);
    }

    public function test_notification_logs_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->student()->create();

        NotificationLog::create(['user_id' => $recipient->id, 'event' => 'student.imported', 'channel' => 'email', 'status' => 'sent']);
        NotificationLog::create(['user_id' => $recipient->id, 'event' => 'teacher.invited', 'channel' => 'email', 'status' => 'failed', 'error' => 'SMTP rejected sender domain']);

        $response = $this->as($admin->createToken('t')->plainTextToken)->getJson('/api/admin/notification-logs?status=failed');

        $response->assertStatus(200);
        $entries = $response->json('data');
        $this->assertCount(1, $entries);
        $this->assertSame('teacher.invited', $entries[0]['event']);
        $this->assertSame('SMTP rejected sender domain', $entries[0]['error']);
    }

    public function test_non_admin_cannot_view_notification_logs(): void
    {
        $teacherUser = User::factory()->teacher()->create();

        $response = $this->as($teacherUser->createToken('t')->plainTextToken)->getJson('/api/admin/notification-logs');

        $response->assertStatus(403);
    }

    /**
     * كان هذا مفقوداً تماماً قبل الإصلاح: لا شيء يحدّث notification_logs عند
     * فشل الإرسال الفعلي — يبقى السطر عالقاً على "queued" للأبد بلا أي فرق
     * ظاهر عن مهمة لم تُعالَج بعد، ولا رسالة الخطأ الحقيقية محفوظة في أي مكان.
     */
    public function test_a_queued_notification_log_is_marked_failed_when_sending_ultimately_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->student()->create();

        $log = NotificationLog::create([
            'user_id' => $recipient->id,
            'event' => 'student.imported',
            'channel' => 'email',
            'status' => 'queued',
        ]);

        $notification = new \App\Notifications\StudentImported(
            \App\Models\AccountInvitation::create([
                'user_id' => $recipient->id,
                'invited_by' => $admin->id,
                'token' => 'test-token-'.uniqid(),
                'expires_at' => now()->addDays(3),
            ])
        );
        $notification->notificationLogIds = ['mail' => $log->id];

        $notification->failed(new \Exception('SMTP rejected sender domain'));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame('SMTP rejected sender domain', $log->error);
    }

    public function test_failed_does_not_overwrite_a_log_that_already_sent_successfully(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->student()->create();

        $log = NotificationLog::create([
            'user_id' => $recipient->id,
            'event' => 'student.imported',
            'channel' => 'database',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $notification = new \App\Notifications\StudentImported(
            \App\Models\AccountInvitation::create([
                'user_id' => $recipient->id,
                'invited_by' => $admin->id,
                'token' => 'test-token-'.uniqid(),
                'expires_at' => now()->addDays(3),
            ])
        );
        $notification->notificationLogIds = ['database' => $log->id];

        $notification->failed(new \Exception('mail channel failed'));

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertNull($log->error);
    }
}
