<?php

namespace Tests\Feature\Search;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_only_verified_teachers(): void
    {
        $this->createTeacher('school', 'verified', ranking: 10);
        $this->createTeacher('school', 'pending_verification', ranking: 90);

        $response = $this->getJson('/api/teachers/search');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_filters_by_teacher_type(): void
    {
        $this->createTeacher('school', 'verified', ranking: 10);
        $this->createTeacher('training_center', 'verified', ranking: 20);

        $response = $this->getJson('/api/teachers/search?teacher_type=training_center');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('training_center', $response->json('data.0.teacher_type'));
    }

    public function test_search_orders_by_ranking_score_descending(): void
    {
        $low = $this->createTeacher('school', 'verified', ranking: 10);
        $high = $this->createTeacher('school', 'verified', ranking: 90);

        $response = $this->getJson('/api/teachers/search');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_search_filters_by_subject(): void
    {
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'مادة']);
        $matching = $this->createTeacher('school', 'verified', ranking: 10);
        $matching->subjects()->attach($subject->id);

        $this->createTeacher('school', 'verified', ranking: 20);

        $response = $this->getJson("/api/teachers/search?subject_id={$subject->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($matching->id, $response->json('data.0.id'));
    }

    private function createTeacher(string $type, string $status, float $ranking): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create([
            'user_id' => $user->id,
            'teacher_type' => $type,
            'status' => $status,
            'ranking_score' => $ranking,
        ]);
    }
}
