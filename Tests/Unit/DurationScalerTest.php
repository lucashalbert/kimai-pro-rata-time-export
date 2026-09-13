<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit;

use KimaiPlugin\ProRataTimeExportBundle\Service\DurationScaler;
use PHPUnit\Framework\TestCase;

/**
 * Spec §35 (core mathematics) and §36 (rounding) worked examples.
 *
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §10, §28, §35, §36
 */
final class DurationScalerTest extends TestCase
{
    private DurationScaler $scaler;

    protected function setUp(): void
    {
        $this->scaler = new DurationScaler();
    }

    public function testCalculateFactorReturnsRatioOfEffectiveToBaseRate(): void
    {
        self::assertSame(1.0, $this->scaler->calculateFactor(150.0, 150.0));
        self::assertSame(0.8, $this->scaler->calculateFactor(120.0, 150.0));
        self::assertSame(0.9, $this->scaler->calculateFactor(135.0, 150.0));
        self::assertSame(1.2, $this->scaler->calculateFactor(180.0, 150.0));
    }

    public function testCalculateFactorRejectsNonPositiveBaseRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->scaler->calculateFactor(120.0, 0.0);
    }

    /**
     * @return iterable<string, array{int, float, float, int}>
     */
    public static function coreMathCases(): iterable
    {
        // Spec §35: factor 1.0.
        yield 'factor 1.0, 60 min' => [60 * 60, 150.0, 150.0, 60];

        // Spec §35: factor 0.8.
        yield 'factor 0.8, 60 min' => [60 * 60, 120.0, 150.0, 48];
        yield 'factor 0.8, 120 min' => [120 * 60, 120.0, 150.0, 96];
        yield 'factor 0.8, 180 min' => [180 * 60, 120.0, 150.0, 144];
        yield 'factor 0.8, 240 min' => [240 * 60, 120.0, 150.0, 192];

        // Spec §35: factor 0.9.
        yield 'factor 0.9, 60 min' => [60 * 60, 135.0, 150.0, 54];

        // Spec §35: factor greater than 1 must work.
        yield 'factor 1.2, 60 min' => [60 * 60, 180.0, 150.0, 72];
    }

    /**
     * @dataProvider coreMathCases
     */
    public function testScaleToMinutesCoreMathExamples(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate,
        int $expectedMinutes
    ): void {
        self::assertSame(
            $expectedMinutes,
            $this->scaler->scaleToMinutes($actualSeconds, $effectiveHourlyRate, $baseRate)
        );
    }

    /**
     * @return iterable<string, array{int, float, float, int}>
     */
    public static function roundingCases(): iterable
    {
        // Spec §36: 37 x .8 = 29.6 -> 30.
        yield '37 x .8 rounds up' => [37 * 60, 120.0, 150.0, 30];

        // Spec §36: 36 x .8 = 28.8 -> 29.
        yield '36 x .8 rounds up to 29' => [36 * 60, 120.0, 150.0, 29];

        // Spec §10: exact-half rounds up (29.5 -> 30).
        yield 'exact half rounds up' => [59 * 60, 75.0, 150.0, 30];

        // Spec §10: 29.4 -> 29 (below half rounds down).
        yield 'below half rounds down' => [49 * 60, 90.0, 150.0, 29];

        // Spec §36: 1 x .8 = .8 -> 1.
        yield '1 x .8 rounds up to 1' => [1 * 60, 120.0, 150.0, 1];

        // Spec §36: zero duration.
        yield 'zero duration stays zero' => [0, 120.0, 150.0, 0];

        // Spec §36: very large duration, no overflow/precision loss.
        // 10,000,000 minutes x 1.0 must round-trip exactly.
        yield 'very large duration' => [10_000_000 * 60, 150.0, 150.0, 10_000_000];
    }

    /**
     * @dataProvider roundingCases
     */
    public function testScaleToMinutesRoundingExamples(
        int $actualSeconds,
        float $effectiveHourlyRate,
        float $baseRate,
        int $expectedMinutes
    ): void {
        self::assertSame(
            $expectedMinutes,
            $this->scaler->scaleToMinutes($actualSeconds, $effectiveHourlyRate, $baseRate)
        );
    }

    public function testRawRateRatioDoesNotLoseExactHalfMinuteToFloatNoise(): void
    {
        self::assertSame(30, $this->scaler->scaleToMinutes(50 * 60, 88.5, 150.0));
    }

    public function testRawRateRatioIsNotQuantizedBeforeFinalRounding(): void
    {
        self::assertSame(1432, $this->scaler->scaleToMinutes(742 * 60, 425.36, 220.48));
    }

    public function testRawRateRatioDoesNotLoseExactHalfMinuteToFloatFactor(): void
    {
        self::assertSame(313, $this->scaler->scaleToMinutes(314 * 60, 100.0, 100.48));
    }

    public function testScaleToMinutesRejectsNegativeInputs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->scaler->scaleToMinutes(-1, 120.0, 150.0);
    }

    public function testScaleToMinutesRejectsNonPositiveBaseRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->scaler->scaleToMinutes(0, 120.0, 0.0);
    }

    public function testScaleToExactMinutesReturnsUnroundedValue(): void
    {
        self::assertEqualsWithDelta(
            29.6,
            $this->scaler->scaleToExactMinutes(37 * 60, 120.0, 150.0),
            1e-9
        );
        self::assertEqualsWithDelta(
            0.0,
            $this->scaler->scaleToExactMinutes(0, 120.0, 150.0),
            1e-9
        );
    }
}
