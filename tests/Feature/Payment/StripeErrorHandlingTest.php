<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Exception\AuthenticationException;
use Tests\TestCase;

/**
 * فشل الاتصال بـ Stripe (مفتاح غير صالح، بطاقة مرفوضة...) كان يصل غير معالَج
 * فيظهر للمستخدم كخطأ 500 فارغ — bootstrap/app.php يلتقطه الآن ويردّ برسالة
 * واضحة بدلاً من ذلك (انظر تقرير الأخطاء على staging لـ POST /bookings/{id}/checkout).
 */
class StripeErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stripe_api_error_during_checkout_returns_a_clean_502_instead_of_a_raw_500(): void
    {
        $subject = Subject::create(['code' => 'stripe-'.uniqid(), 'name_ar' => 'مادة']);
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => 'pending_payment',
        ]);

        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSessionForBooking')
                ->andThrow(AuthenticationException::factory('Invalid API Key provided'));
        });

        $token = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->postJson("/api/bookings/{$booking->id}/checkout");

        $response->assertStatus(502);
        $response->assertJsonPath('status', 'error');
    }
}
