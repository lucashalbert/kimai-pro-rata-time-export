<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

use InvalidArgumentException;

/**
 * Immutable currency amount (spec §27). Stored internally as an integer count
 * of 10^-6 currency units, so every operation is exact 64-bit integer
 * arithmetic: no binary floating-point, no cumulative drift. Rounding to
 * whole cents happens only in format() (spec §28); intermediate values (e.g.
 * a rate × duration factor) keep four extra digits of headroom so a
 * multiplication isn't rounded before the amount is displayed.
 *
 * bcmath/GMP are unavailable in this plugin's target Kimai runtime images, so
 * this avoids that dependency entirely.
 *
 * ponytail: a 64-bit int caps a single value's multiply() around low
 * trillions before overflowing; move to bcmath/GMP if amounts ever approach that.
 */
final class Money
{
    private const SCALE = 6;
    private const DISPLAY_SCALE = 2;

    private function __construct(private readonly int $units)
    {
    }

    public static function fromDecimalString(string $amount): self
    {
        return new self(self::parseToUnits($amount, self::SCALE));
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents * (10 ** (self::SCALE - self::DISPLAY_SCALE)));
    }

    public function add(self $other): self
    {
        return new self($this->units + $other->units);
    }

    public function subtract(self $other): self
    {
        return new self($this->units - $other->units);
    }

    public function multiply(mixed $factor): self
    {
        if (!is_int($factor) && !is_string($factor)) {
            throw new InvalidArgumentException('Factor must be an integer or decimal string.');
        }

        $factorUnits = self::parseToUnits((string) $factor, self::SCALE);

        return new self(self::divRoundHalfUp($this->units * $factorUnits, 10 ** self::SCALE));
    }

    public function multiplyByRatio(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Ratio denominator must be greater than zero.');
        }

        return new self(self::divRoundHalfUp($this->units * $numerator, $denominator));
    }

    public function equals(self $other): bool
    {
        return $this->units === $other->units;
    }

    /**
     * Rounds to whole cents (half-up) only here; every prior operation kept
     * the exact 10^-6 value.
     */
    public function format(): string
    {
        $displayUnits = self::divRoundHalfUp($this->units, 10 ** (self::SCALE - self::DISPLAY_SCALE));

        $negative = $displayUnits < 0;
        $digits = str_pad((string) abs($displayUnits), self::DISPLAY_SCALE + 1, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -self::DISPLAY_SCALE);
        $fraction = substr($digits, -self::DISPLAY_SCALE);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function parseToUnits(string $amount, int $scale): int
    {
        $pattern = '/^(-?)(\d+)(?:\.(\d{1,' . $scale . '}))?$/';

        if (!preg_match($pattern, trim($amount), $matches)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid decimal amount.', $amount));
        }

        $fraction = str_pad($matches[3] ?? '', $scale, '0', STR_PAD_RIGHT);
        $units = ((int) $matches[2]) * (10 ** $scale) + (int) $fraction;

        return $matches[1] === '-' ? -$units : $units;
    }

    private static function divRoundHalfUp(int $numerator, int $denominator): int
    {
        $negative = $numerator < 0;
        $absNumerator = abs($numerator);

        $quotient = intdiv($absNumerator, $denominator);
        $remainder = $absNumerator % $denominator;

        if ($remainder * 2 >= $denominator) {
            ++$quotient;
        }

        return $negative ? -$quotient : $quotient;
    }
}
