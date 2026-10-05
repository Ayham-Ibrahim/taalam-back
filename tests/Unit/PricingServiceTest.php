<?php

namespace Tests\Unit;

use App\Services\PricingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الطالب يدفع سعر المعلم كما حدّده، والمنصة تخصم نسبتها من هذا السعر —
 * فالهامش لا يرفع سعر الطالب أبداً، وصافي المعلم = السعر − حصة المنصة.
 */
class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PricingService;
    }

    public function test_default_60_percent_margin_is_deducted_from_teacher_price(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0);

        $this->assertSame(100.0, $result['student_price']);
        $this->assertSame(60.0, $result['platform_revenue']);
        $this->assertSame(40.0, $result['provider_net']);
    }

    public function test_zero_margin_means_teacher_keeps_everything(): void
    {
        $result = $this->pricing->calculateStudentPrice(200.0, 0.0);

        $this->assertSame(200.0, $result['student_price']);
        $this->assertSame(0.0, $result['platform_revenue']);
        $this->assertSame(200.0, $result['provider_net']);
    }

    public function test_fractional_margin_rounds_to_two_decimals(): void
    {
        $result = $this->pricing->calculateStudentPrice(99.99, 33.33);

        $this->assertSame(99.99, $result['student_price']);
        $this->assertSame(33.33, $result['platform_revenue']);
        $this->assertSame(66.66, $result['provider_net']);
    }

    public function test_student_price_never_exceeds_the_teacher_price(): void
    {
        foreach ([[10, 15], [1234.56, 47.5], [0.5, 99], [50, 100]] as [$price, $margin]) {
            $result = $this->pricing->calculateStudentPrice($price, $margin);

            $this->assertSame((float) $price, $result['student_price']);
        }
    }

    public function test_platform_revenue_plus_provider_net_always_equals_student_price(): void
    {
        foreach ([[10, 15], [1234.56, 47.5], [0.5, 99]] as [$price, $margin]) {
            $result = $this->pricing->calculateStudentPrice($price, $margin);

            $this->assertEqualsWithDelta(
                $result['student_price'],
                $result['platform_revenue'] + $result['provider_net'],
                0.01,
            );
        }
    }

    /** الباقات: teacher_price سعر الساعة/الجلسة الواحدة × sessions_count (units) */
    public function test_units_multiplies_the_unit_price_before_margin_is_deducted(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0, 4);

        $this->assertSame(400.0, $result['provider_total']);
        $this->assertSame(400.0, $result['student_price']);
        $this->assertSame(240.0, $result['platform_revenue']);
        $this->assertSame(160.0, $result['provider_net']);
    }

    public function test_units_defaults_to_one_when_omitted(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0);

        $this->assertSame(100.0, $result['provider_total']);
        $this->assertSame(100.0, $result['student_price']);
    }

    public function test_margin_above_100_percent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->pricing->calculateStudentPrice(100.0, 100.5);
    }

    public function test_negative_margin_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->pricing->calculateStudentPrice(100.0, -1.0);
    }
}
