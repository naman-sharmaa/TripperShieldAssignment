<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PremiumCalculator();
    }

    /** @test */
    public function test_plus_5days_age28_international_300k_yields_225(): void
    {
        // PLUS=20, days=5, age=28(mult=1.0), INTERNATIONAL(1.8), 300000(1.25)
        // 20 * 5 * 1.0 * 1.8 * 1.25 = 225.00
        $premium = $this->calc->calculate('PLUS', 5, 28, 'INTERNATIONAL', 300000);
        $this->assertEquals('225.00', $premium);
    }

    /** @test */
    public function test_basic_1day_age10_domestic_100k(): void
    {
        // BASIC=12, days=1, age=10(mult=1.1), DOMESTIC(1.0), 100k(1.0)
        // 12 * 1 * 1.1 * 1.0 * 1.0 = 13.20
        $premium = $this->calc->calculate('BASIC', 1, 10, 'DOMESTIC', 100000);
        $this->assertEquals('13.20', $premium);
    }

    /** @test */
    public function test_premium_plan_age65_international_500k(): void
    {
        // PREMIUM=30, days=10, age=65(mult=1.5), INTERNATIONAL(1.8), 500k(1.5)
        // 30 * 10 * 1.5 * 1.8 * 1.5 = 1215.00
        $premium = $this->calc->calculate('PREMIUM', 10, 65, 'INTERNATIONAL', 500000);
        $this->assertEquals('1215.00', $premium);
    }

    /** @test */
    public function test_age_multiplier_senior_over_70(): void
    {
        // BASIC=12, days=1, age=75(mult=2.0), DOMESTIC(1.0), 100k(1.0)
        // 12 * 1 * 2.0 = 24.00
        $premium = $this->calc->calculate('BASIC', 1, 75, 'DOMESTIC', 100000);
        $this->assertEquals('24.00', $premium);
    }

    /** @test */
    public function test_calculate_age_on_trip_start_date(): void
    {
        $dob       = new \DateTime('1990-06-15');
        $tripStart = new \DateTime('2024-06-14'); // day BEFORE birthday — still 33
        $age       = $this->calc->calculateAge($dob, $tripStart);
        $this->assertEquals(33, $age);
    }

    /** @test */
    public function test_calculate_age_on_birthday(): void
    {
        $dob       = new \DateTime('1990-06-15');
        $tripStart = new \DateTime('2024-06-15'); // birthday — now 34
        $age       = $this->calc->calculateAge($dob, $tripStart);
        $this->assertEquals(34, $age);
    }

    /** @test */
    public function test_travel_days_inclusive(): void
    {
        $start = new \DateTime('2024-01-01');
        $end   = new \DateTime('2024-01-05');
        $days  = $this->calc->calculateTravelDays($start, $end);
        $this->assertEquals(5, $days);
    }

    /** @test */
    public function test_single_day_trip(): void
    {
        $start = new \DateTime('2024-01-01');
        $end   = new \DateTime('2024-01-01');
        $days  = $this->calc->calculateTravelDays($start, $end);
        $this->assertEquals(1, $days);
    }

    /** @test */
    public function test_invalid_plan_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate('GOLD', 5, 28, 'DOMESTIC', 100000);
    }

    /** @test */
    public function test_invalid_destination_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate('BASIC', 5, 28, 'EUROPE', 100000);
    }

    /** @test */
    public function test_invalid_sum_insured_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate('BASIC', 5, 28, 'DOMESTIC', 200000);
    }
}
