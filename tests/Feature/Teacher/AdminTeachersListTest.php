<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * status=pending في الفلتر يعني الحالة المبسّطة (AdminTeacherResource::displayStatus)
 * التي تجمع active_unverified وpending_verification معاً — لا القيمة الخام
 * الحرفية لعمود teachers.status (RULE، نفس نمط ClassSessionController).
 */
class AdminTeachersListTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_status_filter_matches_both_active_unverified_and_pending_verification(): void
    {
        [, $adminToken] = $this->createAdmin();

        $activeUnverified = $this->createTeacher('active_unverified');
        $pendingVerification = $this->createTeacher('pending_verification');
        $verified = $this->createTeacher('verified');
        $rejected = $this->createTeacher('rejected');

        $response = $this->as($adminToken)->getJson('/api/teachers?status=pending');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($activeUnverified->id, $ids);
        $this->assertContains($pendingVerification->id, $ids);
        $this->assertNotContains($verified->id, $ids);
        $this->assertNotContains($rejected->id, $ids);
    }

    #[DataProvider('literalStatusProvider')]
    public function test_other_statuses_still_filter_by_literal_match(string $status): void
    {
        [, $adminToken] = $this->createAdmin();

        $matching = $this->createTeacher($status);
        $other = $this->createTeacher('pending_verification');

        $response = $this->as($adminToken)->getJson("/api/teachers?status={$status}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public static function literalStatusProvider(): array
    {
        return [
            'verified' => ['verified'],
            'rejected' => ['rejected'],
            'suspended' => ['suspended'],
        ];
    }

    private function createTeacher(string $status): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => $status]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }
}
