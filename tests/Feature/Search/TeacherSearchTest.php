<?php

namespace Tests\Feature\Search;

use App\Models\Language;
use App\Models\Package;
use App\Models\Stage;
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

    /**
     * المرحلة تُستنتَج من الباقات الفعلية القابلة للحجز (package_stage)، لا من
     * تصنيف عام يربط المادة بمرحلة (subject_stage) — راجع الاختبار التالي
     * الذي يثبت أن التصنيف العام وحده لم يعد كافياً لإظهار المعلم.
     */
    public function test_search_filters_by_stage(): void
    {
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'مادة']);
        $stage = Stage::create(['code' => 'stg-'.uniqid(), 'name_ar' => 'مرحلة']);

        $matching = $this->createTeacher('school', 'verified', ranking: 10);
        $matchingPackage = $this->createPackage($matching, $subject, 100);
        $matchingPackage->stages()->attach($stage->id);

        $other = $this->createTeacher('school', 'verified', ranking: 20);
        $this->createPackage($other, $subject, 100);

        $response = $this->getJson("/api/teachers/search?stage_id={$stage->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($matching->id, $response->json('data.0.id'));
    }

    public function test_stage_filter_ignores_generic_subject_taxonomy_without_a_matching_package(): void
    {
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'مادة']);
        $stage = Stage::create(['code' => 'stg-'.uniqid(), 'name_ar' => 'مرحلة']);
        $subject->stages()->attach($stage->id);

        $teacher = $this->createTeacher('school', 'verified', ranking: 10);
        $teacher->subjects()->attach($subject->id);
        $this->createPackage($teacher, $subject, 100);

        $response = $this->getJson("/api/teachers/search?stage_id={$stage->id}");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_search_filters_by_grade(): void
    {
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'مادة']);

        $matching = $this->createTeacher('school', 'verified', ranking: 10);
        $matchingPackage = $this->createPackage($matching, $subject, 100);
        $matchingPackage->update(['grades' => [7, 8, 9]]);

        $other = $this->createTeacher('school', 'verified', ranking: 20);
        $otherPackage = $this->createPackage($other, $subject, 100);
        $otherPackage->update(['grades' => [10, 11, 12]]);

        $response = $this->getJson('/api/teachers/search?grade=8');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($matching->id, $response->json('data.0.id'));
    }

    public function test_search_filters_by_language(): void
    {
        $language = Language::create(['code' => substr(uniqid(), -6), 'name_ar' => 'لغة']);
        $matching = $this->createTeacher('school', 'verified', ranking: 10);
        $matching->languages()->attach($language->id);

        $this->createTeacher('school', 'verified', ranking: 20);

        $response = $this->getJson("/api/teachers/search?language_id={$language->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($matching->id, $response->json('data.0.id'));
    }

    public function test_search_filters_by_price_range(): void
    {
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'مادة']);
        $cheap = $this->createTeacher('school', 'verified', ranking: 10);
        $this->createPackage($cheap, $subject, 100);

        $expensive = $this->createTeacher('school', 'verified', ranking: 20);
        $this->createPackage($expensive, $subject, 400);

        $response = $this->getJson('/api/teachers/search?min_price=200&max_price=500');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($expensive->id, $response->json('data.0.id'));
    }

    private function createPackage(Teacher $teacher, Subject $subject, float $studentPrice): Package
    {
        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => $studentPrice * 0.6,
            'platform_margin_percent' => 40,
            'student_price' => $studentPrice,
            'platform_revenue' => $studentPrice * 0.4,
            'status' => 'active',
            'approved_at' => now(),
        ]);
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
