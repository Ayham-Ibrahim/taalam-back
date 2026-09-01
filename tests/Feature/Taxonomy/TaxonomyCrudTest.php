<?php

namespace Tests\Feature\Taxonomy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_taxonomy_items(): void
    {
        $this->postJson('/api/taxonomy/languages', [
            'code' => 'ar', 'name_ar' => 'العربية',
        ], $this->adminHeaders());

        $response = $this->getJson('/api/taxonomy/languages');

        $response->assertStatus(200)->assertJsonPath('status', 'success');
    }

    public function test_non_admin_cannot_create_taxonomy_item(): void
    {
        $student = User::factory()->student()->create();
        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/taxonomy/languages', ['code' => 'fr', 'name_ar' => 'الفرنسية']);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_update_and_delete_taxonomy_item(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/taxonomy/curricula', [
                'code' => 'national', 'name_ar' => 'وطني',
            ]);

        $create->assertStatus(201)->assertJsonPath('status', 'success');
        $id = $create->json('data.id');

        $update = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/taxonomy/curricula/{$id}", [
                'code' => 'national', 'name_ar' => 'وطني معدّل',
            ]);
        $update->assertStatus(200)->assertJsonPath('data.name_ar', 'وطني معدّل');

        $delete = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/taxonomy/curricula/{$id}");
        $delete->assertStatus(200);

        $this->assertDatabaseMissing('curricula', ['id' => $id]);
    }

    /**
     * الاسم في الكود مطابق لاسم الجدول course_fields (شرطة سفلية) — وليس
     * course-fields، ليتطابق مع كل مستهلكي هذا النوع في الواجهة الأمامية.
     */
    public function test_admin_can_create_and_list_course_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/taxonomy/course_fields', [
                'code' => 'programming', 'name_ar' => 'برمجة',
            ]);
        $create->assertStatus(201)->assertJsonPath('data.name_ar', 'برمجة');

        $list = $this->getJson('/api/taxonomy/course_fields');
        $list->assertStatus(200);
        $this->assertContains('برمجة', collect($list->json('data'))->pluck('name_ar')->all());
    }

    /**
     * education_type إلزامي على subjects في القاعدة (عمود enum بلا nullable)،
     * تماماً مثل stages — يجب أن ترجع رسالة عربية واضحة، لا رسالة Laravel
     * الإنجليزية الافتراضية (لا يوجد lang/ar في هذا المشروع).
     */
    public function test_creating_a_subject_without_education_type_is_rejected_with_an_arabic_message(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/taxonomy/subjects', ['code' => 'math', 'name_ar' => 'رياضيات']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.education_type.0', 'نوع التعليم مطلوب');
        $this->assertDatabaseMissing('subjects', ['code' => 'math']);
    }

    public function test_admin_can_create_a_subject_with_education_type(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/taxonomy/subjects', [
                'code' => 'math', 'name_ar' => 'رياضيات', 'education_type' => 'school',
            ]);

        $response->assertStatus(201)->assertJsonPath('data.education_type', 'school');
    }

    public function test_unknown_taxonomy_type_returns_404(): void
    {
        $response = $this->getJson('/api/taxonomy/unknown-type');

        $response->assertStatus(404);
    }

    private function adminHeaders(): array
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }
}
