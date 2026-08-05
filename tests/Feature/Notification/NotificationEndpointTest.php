<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Notifications\TeacherVerificationReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_their_own_notifications_with_unread_count(): void
    {
        $user = User::factory()->teacher()->create();
        $user->notify(new TeacherVerificationReviewed(true));
        $token = $user->createToken('t')->plainTextToken;

        $list = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications');
        $list->assertStatus(200);
        $list->assertJsonPath('data.0.type', 'TeacherVerificationReviewed');
        $list->assertJsonPath('data.0.data.approved', true);
        $this->assertNull($list->json('data.0.readAt'));

        $count = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications/unread-count');
        $count->assertStatus(200);
        $count->assertJsonPath('data.count', 1);
    }

    public function test_user_can_mark_a_single_notification_read(): void
    {
        $user = User::factory()->teacher()->create();
        $user->notify(new TeacherVerificationReviewed(true));
        $token = $user->createToken('t')->plainTextToken;

        $notificationId = $user->notifications()->first()->id;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/notifications/{$notificationId}/read");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.readAt'));

        $count = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications/unread-count');
        $count->assertJsonPath('data.count', 0);
    }

    public function test_user_can_mark_all_notifications_read(): void
    {
        $user = User::factory()->teacher()->create();
        $user->notify(new TeacherVerificationReviewed(true));
        $user->notify(new TeacherVerificationReviewed(false, 'سبب'));
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/notifications/mark-all-read');
        $response->assertStatus(200);

        $count = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications/unread-count');
        $count->assertJsonPath('data.count', 0);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->teacher()->create();
        $owner->notify(new TeacherVerificationReviewed(true));
        $notificationId = $owner->notifications()->first()->id;

        $intruder = User::factory()->teacher()->create();
        $intruderToken = $intruder->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->postJson("/api/notifications/{$notificationId}/read");

        $response->assertStatus(404);
    }
}
