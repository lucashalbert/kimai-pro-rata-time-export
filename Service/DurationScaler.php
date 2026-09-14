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
    private const SECONDS_PER_MINUTE = 60;

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

        [$numerator, $denominator] = $this->scaleToExactMinutesRatio($actualSeconds, $effectiveHourlyRate, $baseRate);

        return self::divRoundHalfUp($numerator, $denominator);
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

        [$numerator, $denominator] = $this->scaleToExactMinutesRatio($actualSeconds, $effectiveHourlyRate, $baseRate);

        return $numerator / $denominator;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleToExactMinutesRatio(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate
    ): array {
        $this->assertValidBaseRate($baseRate);
        $this->assertFiniteRate($effectiveHourlyRate, 'Effective hourly rate');
        $this->assertFiniteRate($baseRate, 'Base rate');

        [$effectiveNumerator, $effectiveDenominator] = self::parseDecimalRatio($this->floatToDecimal($effectiveHourlyRate));
        [$baseNumerator, $baseDenominator] = self::parseDecimalRatio($this->floatToDecimal($baseRate));

        $numeratorParts = [$actualSeconds, $effectiveNumerator, $baseDenominator];
        $denominatorParts = [self::SECONDS_PER_MINUTE, $effectiveDenominator, $baseNumerator];

        self::reduceParts($numeratorParts, $denominatorParts);

        return [
            array_product($numeratorParts),
            array_product($denominatorParts),
        ];
    }

    private function assertValidBaseRate(float $baseRate): void
    {
        if ($baseRate <= 0.0) {
            throw new \InvalidArgumentException('Base rate must be greater than zero.');
        }

        $this->assertFiniteRate($baseRate, 'Base rate');
    }

    private function assertFiniteRate(float $value, string $label): void
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException($label . ' must be finite.');
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

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseDecimalRatio(string $amount): array
    {
        $pattern = '/^(-?)(\d+)(?:\.(\d+))?$/';

        if (!preg_match($pattern, trim($amount), $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid decimal amount.', $amount));
        }

        $fraction = rtrim($matches[3] ?? '', '0');
        $denominator = 10 ** strlen($fraction);
        $numerator = ((int) $matches[2]) * $denominator + ($fraction === '' ? 0 : (int) $fraction);

        if ($matches[1] === '-') {
            $numerator = -$numerator;
        }

        $gcd = self::gcd(abs($numerator), $denominator);

        return [intdiv($numerator, $gcd), intdiv($denominator, $gcd)];
    }

    /**
     * @param int[] $numeratorParts
     * @param int[] $denominatorParts
     */
    private static function reduceParts(array &$numeratorParts, array &$denominatorParts): void
    {
        foreach ($numeratorParts as $numeratorIndex => $numeratorPart) {
            foreach ($denominatorParts as $denominatorIndex => $denominatorPart) {
                $gcd = self::gcd(abs($numeratorPart), abs($denominatorPart));

                if ($gcd <= 1) {
                    continue;
                }

                $numeratorPart = intdiv($numeratorPart, $gcd);
                $denominatorPart = intdiv($denominatorPart, $gcd);
                $denominatorParts[$denominatorIndex] = $denominatorPart;

                if ($numeratorPart === 1) {
                    break;
                }
            }

            $numeratorParts[$numeratorIndex] = $numeratorPart;
        }
    }

    private static function divRoundHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($remainder * 2 >= $denominator) {
            ++$quotient;
        }

        return $quotient;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
