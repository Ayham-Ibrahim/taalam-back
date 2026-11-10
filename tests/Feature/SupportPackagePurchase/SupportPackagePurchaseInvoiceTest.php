<?php

namespace Tests\Feature\SupportPackagePurchase;

use App\Models\AcademicSupporter;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SupportPackage;
use App\Models\SupportPackagePurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportPackagePurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_download_a_pdf_invoice_for_their_confirmed_purchase(): void
    {
        [$studentUser, $purchase] = $this->createPurchase();

        $this->withToken($studentUser->createToken('t')->plainTextToken)
            ->get("/api/support-package-purchases/{$purchase->id}/invoice")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_student_cannot_download_someone_elses_invoice(): void
    {
        [, $purchase] = $this->createPurchase();

        $otherUser = User::factory()->student()->create();
        Student::create(['user_id' => $otherUser->id, 'education_type' => 'university']);

        $this->withToken($otherUser->createToken('t')->plainTextToken)
            ->get("/api/support-package-purchases/{$purchase->id}/invoice")
            ->assertForbidden();
    }

    public function test_downloading_an_invoice_for_an_unpaid_purchase_is_rejected(): void
    {
        [$studentUser, $purchase] = $this->createPurchase(status: 'pending_payment');

        $this->withToken($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/support-package-purchases/{$purchase->id}/invoice")
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: SupportPackagePurchase}
     */
    private function createPurchase(string $status = 'confirmed'): array
    {
        $supporterUser = User::factory()->create(['role' => 'academic_support']);
        $supporter = AcademicSupporter::create(['user_id' => $supporterUser->id]);

        $package = SupportPackage::create([
            'academic_supporter_id' => $supporter->id,
            'title' => 'باقة دعم',
            'tier' => 'basic',
            'description' => 'وصف',
            'student_price' => 100,
            'supporter_profit_percent' => 70,
            'platform_margin_percent' => 30,
            'teacher_price' => 70,
            'platform_revenue' => 30,
            'status' => 'active',
        ]);
        $package->subjects()->sync([Subject::create(['code' => 'spi-'.uniqid(), 'name_ar' => 'تشريح'])->id]);

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'university']);

        $purchase = SupportPackagePurchase::create([
            'student_id' => $student->id,
            'academic_supporter_id' => $supporter->id,
            'support_package_id' => $package->id,
            'amount' => 100,
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
        ]);

        return [$studentUser, $purchase];
    }
}
