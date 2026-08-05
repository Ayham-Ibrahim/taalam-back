<?php

namespace Tests\Unit;

use App\Services\PricingService;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PricingService;
    }

    public function test_default_60_percent_margin(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0);

        $this->assertSame(160.0, $result['student_price']);
        $this->assertSame(60.0, $result['platform_revenue']);
    }

    public function test_zero_margin_means_student_pays_teacher_price(): void
    {
        $result = $this->pricing->calculateStudentPrice(200.0, 0.0);

        $this->assertSame(200.0, $result['student_price']);
        $this->assertSame(0.0, $result['platform_revenue']);
    }

    public function test_fractional_margin_rounds_to_two_decimals(): void
    {
        $result = $this->pricing->calculateStudentPrice(99.99, 33.33);

        $this->assertSame(133.32, $result['student_price']);
        $this->assertSame(33.33, $result['platform_revenue']);
    }

    public function test_high_margin(): void
    {
        $result = $this->pricing->calculateStudentPrice(50.0, 200.0);

        $this->assertSame(150.0, $result['student_price']);
        $this->assertSame(100.0, $result['platform_revenue']);
    }

    public function test_platform_revenue_always_equals_student_price_minus_provider_price(): void
    {
        foreach ([[10, 15], [1234.56, 47.5], [0.5, 999]] as [$price, $margin]) {
            $result = $this->pricing->calculateStudentPrice($price, $margin);

            $this->assertEqualsWithDelta(
                $result['student_price'] - $price,
                $result['platform_revenue'],
                0.01,
            );
        }
    }

    /** الباقات: teacher_price سعر الساعة/الجلسة الواحدة × sessions_count (units) قبل هامش المنصة */
    public function test_units_multiplies_the_unit_price_before_margin_is_applied(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0, 4);

        $this->assertSame(400.0, $result['provider_total']);
        $this->assertSame(640.0, $result['student_price']);
        $this->assertSame(240.0, $result['platform_revenue']);
    }

    public function test_units_defaults_to_one_when_omitted(): void
    {
        $result = $this->pricing->calculateStudentPrice(100.0, 60.0);

        $this->assertSame(100.0, $result['provider_total']);
        $this->assertSame(160.0, $result['student_price']);
    }
}
