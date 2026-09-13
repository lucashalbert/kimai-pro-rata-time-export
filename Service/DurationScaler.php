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
    /**
     * Decimal places kept for the factor when it enters bcmath arithmetic.
     * Comfortably exceeds a PHP float's ~15-17 significant digits, so no
     * precision the caller could have supplied is discarded.
     */
    private const BC_SCALE = 20;

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
     * Applies the factor at full precision and rounds exactly once. A zero
     * actual duration yields zero equivalent minutes and never a negative value
     * (spec §14).
     *
     * Rounding is done entirely in bcmath's arbitrary-precision decimal domain
     * (spec §27/§28) rather than via native float comparison, so a
     * mathematically exact half-minute can never be misclassified by binary
     * floating-point drift (e.g. a true 29.5 represented as 29.499999999999996).
     *
     * @param int $actualSeconds recorded duration from ExportableItem::getDuration()
     */
    public function scaleToMinutes(int $actualSeconds, float $factor): int
    {
        $exactMinutes = bcdiv(
            bcmul((string) $actualSeconds, sprintf('%.' . self::BC_SCALE . 'F', $factor), self::BC_SCALE),
            '60',
            self::BC_SCALE
        );

        // bcadd truncates rather than rounds when reducing scale, so adding
        // 0.5 before truncating to scale 0 implements round-half-up.
        return (int) bcadd(bcadd($exactMinutes, '0.5', self::BC_SCALE), '0', 0);
    }

    /**
     * The unrounded equivalent duration in minutes, retained so the export can
     * disclose the rounding difference rather than hide it (spec §21, §47).
     */
    public function scaleToExactMinutes(int $actualSeconds, float $factor): float
    {
        return ($actualSeconds / 60.0) * $factor;
    }
}
