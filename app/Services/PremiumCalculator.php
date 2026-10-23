<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * PremiumCalculator - uses integer-scaled arithmetic to avoid floating-point errors.
 * All intermediate values are worked in integer units (paise = 1/100 rupee).
 */
class PremiumCalculator
{
    // Base daily rates in paise (1 INR = 100 paise)
    private const BASE_RATES = [
        'BASIC'   => 1200,  // ₹12.00
        'PLUS'    => 2000,  // ₹20.00
        'PREMIUM' => 3000,  // ₹30.00
    ];

    // Age multipliers stored as (numerator, denominator) pairs for exact fractions
    // 0-17: 1.10 = 110/100
    // 18-60: 1.00 = 100/100
    // 61-70: 1.50 = 150/100
    // 71+: 2.00 = 200/100
    private function ageMultiplier(int $age): array
    {
        return match (true) {
            $age <= 17 => [110, 100],
            $age <= 60 => [100, 100],
            $age <= 70 => [150, 100],
            default    => [200, 100],
        };
    }

    // Destination multipliers
    // DOMESTIC: 1.00 = 100/100
    // INTERNATIONAL: 1.80 = 180/100
    private function destinationMultiplier(string $destination): array
    {
        return match ($destination) {
            'DOMESTIC'      => [100, 100],
            'INTERNATIONAL' => [180, 100],
            default => throw new InvalidArgumentException("Invalid destination: $destination"),
        };
    }

    // Coverage multipliers
    // 100000: 1.00 = 100/100
    // 300000: 1.25 = 125/100
    // 500000: 1.50 = 150/100
    private function coverageMultiplier(int $sumInsured): array
    {
        return match ($sumInsured) {
            100000 => [100, 100],
            300000 => [125, 100],
            500000 => [150, 100],
            default => throw new InvalidArgumentException("Invalid sum_insured: $sumInsured"),
        };
    }

    /**
     * Calculate premium using integer arithmetic.
     *
     * Formula: base_daily_rate × days × age_mult × dest_mult × coverage_mult
     *
     * Example: PLUS, 5 days, age 28, INTERNATIONAL, 300000
     * = 2000 * 5 * (100/100) * (180/100) * (125/100)
     * = 2000 * 5 * 1 * 1.8 * 1.25
     * = 22500 paise = ₹225.00
     */
    public function calculate(
        string $planCode,
        int    $travelDays,
        int    $age,
        string $destination,
        int    $sumInsured
    ): string {
        if (!isset(self::BASE_RATES[$planCode])) {
            throw new InvalidArgumentException("Invalid plan_code: $planCode");
        }

        $basePaise = self::BASE_RATES[$planCode];

        // Work in integer numerator over compound denominator
        // Intermediate: base * days in paise
        $numerator   = $basePaise * $travelDays;
        $denominator = 1;

        [$aN, $aD] = $this->ageMultiplier($age);
        [$dN, $dD] = $this->destinationMultiplier($destination);
        [$cN, $cD] = $this->coverageMultiplier($sumInsured);

        // Multiply numerators and denominators
        $numerator   *= $aN * $dN * $cN;  // e.g. 100 * 180 * 125
        $denominator  = $aD * $dD * $cD;  // e.g. 100 * 100 * 100 = 1,000,000

        // Result in paise (integer division with remainder handling)
        // To get exact 2-decimal-place result, we need to round to nearest paisa
        // numerator/denominator gives paise; divide by 100 for rupees
        // We round at the paise level (no sub-paisa), then convert to rupees with 2dp

        // Total in paise * 100 / denominator to keep precision, then round
        // Actually: result_paise = round(numerator / denominator)
        // result_rupees = result_paise / 100 with 2dp

        // To avoid float: use bcmath
        $numStr    = (string) $numerator;
        $denomStr  = (string) $denominator;

        // Calculate paise with scale=2 to allow rounding
        $paiseWithFrac = bcdiv($numStr, $denomStr, 2);

        // Round to nearest integer paise
        $paise = (int) bcround($paiseWithFrac, 0);

        // Convert paise to rupees string with exactly 2 decimal places
        $rupees       = intdiv($paise, 100);
        $paisePart    = $paise % 100;
        $premiumStr   = sprintf('%d.%02d', $rupees, $paisePart);

        return $premiumStr;
    }

    /**
     * Calculate customer age on a specific reference date (trip_start).
     */
    public function calculateAge(\DateTimeInterface $dob, \DateTimeInterface $referenceDate): int
    {
        $dobObj = \DateTime::createFromInterface($dob);
        $refObj = \DateTime::createFromInterface($referenceDate);
        $diff   = $refObj->diff($dobObj);
        return (int) $diff->y;
    }

    /**
     * Calculate inclusive travel days: (trip_end - trip_start) + 1
     */
    public function calculateTravelDays(\DateTimeInterface $tripStart, \DateTimeInterface $tripEnd): int
    {
        $start = \DateTime::createFromInterface($tripStart);
        $end   = \DateTime::createFromInterface($tripEnd);
        $diff  = $end->diff($start);
        return (int) $diff->days + 1;
    }
}
