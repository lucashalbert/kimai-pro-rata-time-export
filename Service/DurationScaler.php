<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

/**
 * Converts an actual recorded duration into a compensation-equivalent duration
 * at the employer base rate, and owns the single rounding step (spec §8, §10).
 *
 * Spec §10 requires the rounding policy to be centralised here and unit tested.
 * Default policy is nearest whole minute, halves rounding up: 29.4 -> 29,
 * 29.5 -> 30, 29.6 -> 30.
 *
 * Spec §27/§28 require decimal or integer arithmetic rather than accumulating
 * binary float error, and forbid rounding intermediate values. Kimai stores
 * durations as integer seconds, so the input stays in seconds and minute
 * rounding happens exactly once, at the end.
 */
final class DurationScaler
{
    private const BC_SCALE = 20;

    private const SECONDS_PER_MINUTE = '60';

    /**
     * The conversion factor: effective rate divided by employer base rate.
     *
     * Spec §35 requires factors greater than 1 to work (a record rated above
     * the base rate expands rather than shrinks).
     *
     * @throws \InvalidArgumentException when $baseRate is not greater than zero
     */
    public function calculateFactor(float $effectiveHourlyRate, float $baseRate): float
    {
        if ($baseRate <= 0.0) {
            throw new \InvalidArgumentException('Base rate must be greater than zero.');
        }

        return $effectiveHourlyRate / $baseRate;
    }

    /**
     * Equivalent duration in whole minutes for an actual duration in seconds.
     *
     * Applies the rate ratio at full precision and rounds exactly once. A zero
     * actual duration yields zero equivalent minutes and never a negative value
     * (spec §14).
     *
     * @param int $actualSeconds recorded duration from ExportableItem::getDuration()
     */
    public function scaleToMinutes(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate
    ): int {
        if ($actualSeconds < 0) {
            throw new \InvalidArgumentException('Actual duration must not be negative.');
        }

        if ($effectiveHourlyRate < 0.0) {
            throw new \InvalidArgumentException('Effective hourly rate must not be negative.');
        }

        if ($actualSeconds === 0) {
            $this->assertValidBaseRate($baseRate);
            return 0;
        }

        $exactMinutes = $this->scaleToExactMinutesDecimal($actualSeconds, $effectiveHourlyRate, $baseRate);
        return (int) \bcadd(\bcadd($exactMinutes, '0.5', self::BC_SCALE), '0', 0);
    }

    /**
     * The unrounded equivalent duration in minutes, retained so the export can
     * disclose the rounding difference rather than hide it (spec §21, §47).
     */
    public function scaleToExactMinutes(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate
    ): float {
        if ($actualSeconds < 0) {
            throw new \InvalidArgumentException('Actual duration must not be negative.');
        }

        if ($effectiveHourlyRate < 0.0) {
            throw new \InvalidArgumentException('Effective hourly rate must not be negative.');
        }

        return (float) $this->scaleToExactMinutesDecimal($actualSeconds, $effectiveHourlyRate, $baseRate);
    }

    private function scaleToExactMinutesDecimal(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate
    ): string {
        $this->assertValidBaseRate($baseRate);

        $numerator = \bcmul(
            (string) $actualSeconds,
            $this->floatToDecimal($effectiveHourlyRate),
            self::BC_SCALE
        );
        $denominator = \bcmul(
            $this->floatToDecimal($baseRate),
            self::SECONDS_PER_MINUTE,
            self::BC_SCALE
        );

        return \bcdiv($numerator, $denominator, self::BC_SCALE);
    }

    private function assertValidBaseRate(float $baseRate): void
    {
        if ($baseRate <= 0.0) {
            throw new \InvalidArgumentException('Base rate must be greater than zero.');
        }
    }

    private function floatToDecimal(float $value): string
    {
        $decimal = \var_export($value, true);

        if (!str_contains($decimal, 'E') && !str_contains($decimal, 'e')) {
            return $decimal;
        }

        return sprintf('%.20F', $value);
    }
}
