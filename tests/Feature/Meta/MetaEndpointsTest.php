<?php

namespace Tests\Feature\Meta;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_endpoint_is_public_and_returns_four_items(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $response = $this->getJson('/api/meta/stats');

        $response->assertStatus(200);
        $keys = collect($response->json('data'))->pluck('key');
        $this->assertEquals(['rating', 'students', 'sessions', 'teachers'], $keys->all());
        $response->assertJsonPath('data.3.value', 1);
    }

    public function test_filters_endpoint_is_public_and_lists_active_subjects(): void
    {
        Subject::create(['code' => 'math', 'name_ar' => 'رياضيات', 'education_type' => 'school', 'is_active' => true]);
        Subject::create(['code' => 'old', 'name_ar' => 'قديمة', 'education_type' => 'school', 'is_active' => false]);

        $response = $this->getJson('/api/meta/filters');

        $response->assertStatus(200);
        $subjectLabels = collect($response->json('data.subjects'))->pluck('label');
        $this->assertTrue($subjectLabels->contains('رياضيات'));
        $this->assertFalse($subjectLabels->contains('قديمة'));
        $this->assertCount(12, $response->json('data.grades'));
    }
}
