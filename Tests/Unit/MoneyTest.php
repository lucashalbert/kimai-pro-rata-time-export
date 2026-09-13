<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit;

use InvalidArgumentException;
use KimaiPlugin\ProRataTimeExportBundle\Model\Money;
use PHPUnit\Framework\TestCase;
use TypeError;

final class MoneyTest extends TestCase
{
    public function testConstructsFromCommonDecimalStrings(): void
    {
        self::assertSame('150.00', Money::fromDecimalString('150.00')->format());
        self::assertSame('119.99', Money::fromDecimalString('119.99')->format());
    }

    public function testConstructsFromIntegerCents(): void
    {
        self::assertSame('150.00', Money::fromCents(15000)->format());
    }

    public function testRejectsAnInvalidDecimalString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('not-a-number');
    }

    public function testAdditionSumsTwoAmountsExactly(): void
    {
        $sum = Money::fromDecimalString('150.00')->add(Money::fromDecimalString('119.99'));

        self::assertSame('269.99', $sum->format());
    }

    public function testMultiplyByAFractionalFactorRoundsOnlyAtDisplayTime(): void
    {
        // 119.99 * 0.8 = 95.992 exactly; only format() rounds it, to 95.99.
        $result = Money::fromDecimalString('119.99')->multiply('0.8');

        self::assertSame('95.99', $result->format());
        self::assertTrue($result->equals(Money::fromDecimalString('95.992')));
        self::assertFalse($result->equals(Money::fromDecimalString('95.99')));
    }

    public function testMultiplyRejectsFloatFactors(): void
    {
        $this->expectException(TypeError::class);

        Money::fromDecimalString('150000.00')->multiply(100 / 150);
    }

    public function testMultiplyByRatioPreservesExactProRataConversion(): void
    {
        $result = Money::fromDecimalString('150000.00')->multiplyByRatio(100, 150);

        self::assertSame('100000.00', $result->format());
        self::assertTrue($result->equals(Money::fromDecimalString('100000.00')));
    }

    public function testRepeatedAdditionDoesNotDriftLikeNaiveFloatArithmetic(): void
    {
        // Under naive float arithmetic, summing 0.1 thirty times yields
        // 2.9999999999999996, not 3.00. Integer-unit Money must not drift.
        $sum = Money::fromDecimalString('0.00');

        for ($i = 0; $i < 30; ++$i) {
            $sum = $sum->add(Money::fromDecimalString('0.10'));
        }

        self::assertSame('3.00', $sum->format());
        self::assertTrue($sum->equals(Money::fromDecimalString('3.00')));
    }

    public function testReconciliationVarianceFromSpecSection21(): void
    {
        $actual = Money::fromDecimalString('9742.00');
        $equivalent = Money::fromDecimalString('9741.25');

        $variance = $equivalent->subtract($actual);

        self::assertSame('-0.75', $variance->format());
    }
}
