<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit;

use KimaiPlugin\ProRataTimeExportBundle\Service\IntervalGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Spec §37, §16: interval generation. Spec §38: DST transitions.
 *
 * DST fixtures use real 2026 transition dates for America/New_York
 * (spring forward 2026-03-08, fall back 2026-11-01). They use offset-free
 * strings plus a named DateTimeZone so PHP applies the zone's DST rules.
 */
final class IntervalGeneratorTest extends TestCase
{
    private IntervalGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IntervalGenerator();
    }

    public function testAddsMinutesWithinTheSameDay(): void
    {
        $begin = new \DateTimeImmutable('2026-09-03 09:00:00', new \DateTimeZone('UTC'));

        $end = $this->generator->generateEnd($begin, 192);

        self::assertSame('2026-09-03 12:12:00', $end->format('Y-m-d H:i:s'));
    }

    public function testCrossesMidnightRollingTheDateForward(): void
    {
        $begin = new \DateTimeImmutable('2026-09-03 23:00:00', new \DateTimeZone('UTC'));

        $end = $this->generator->generateEnd($begin, 90);

        self::assertSame('2026-09-04 00:30:00', $end->format('Y-m-d H:i:s'));
    }

    public function testCrossesMidnightByTwoMinutes(): void
    {
        $begin = new \DateTimeImmutable('2026-09-03 23:59:00', new \DateTimeZone('UTC'));

        $end = $this->generator->generateEnd($begin, 2);

        self::assertSame('2026-09-04 00:01:00', $end->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function midnightCrossingSourceProvider(): array
    {
        return [
            '23:00 -> 01:00' => ['23:00:00', 120, '2026-09-04 01:00:00'],
            '22:30 -> 00:30' => ['22:30:00', 120, '2026-09-04 00:30:00'],
            '23:59 -> 00:01' => ['23:59:00', 2, '2026-09-04 00:01:00'],
        ];
    }

    /**
     * @dataProvider midnightCrossingSourceProvider
     */
    public function testMidnightCrossingSourceRecords(string $beginTime, int $equivalentMinutes, string $expected): void
    {
        $begin = new \DateTimeImmutable('2026-09-03 ' . $beginTime, new \DateTimeZone('UTC'));

        $end = $this->generator->generateEnd($begin, $equivalentMinutes);

        self::assertSame($expected, $end->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-04', $end->format('Y-m-d'), 'the date component must actually roll over');
    }

    public function testZeroEquivalentMinutesReturnsTheStartInstant(): void
    {
        $begin = new \DateTimeImmutable('2026-09-03 09:00:00', new \DateTimeZone('UTC'));

        $end = $this->generator->generateEnd($begin, 0);

        self::assertSame($begin->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
    }

    public function testSpringForwardDstTransitionUsesRealElapsedMinutes(): void
    {
        // 2026-03-08: America/New_York clocks jump from 02:00 EST to 03:00 EDT.
        // No explicit offset in the string: PHP ignores the DateTimeZone argument
        // and falls back to a fixed offset (no DST awareness) whenever the parsed
        // string already carries one, so the fixture must stay tzdata-only.
        $begin = new \DateTimeImmutable('2026-03-08 01:30:00', new \DateTimeZone('America/New_York'));

        $end = $this->generator->generateEnd($begin, 90);

        // Naive wall-clock addition would say 03:00; the missing hour means
        // only 30 of those 90 elapsed minutes land before the jump, so the
        // correct result is 04:00 EDT.
        self::assertSame('2026-03-08 04:00:00', $end->format('Y-m-d H:i:s'));
        self::assertSame('-04:00', $end->format('P'));
        self::assertSame(5400, $end->getTimestamp() - $begin->getTimestamp());
    }

    public function testFallBackDstTransitionUsesRealElapsedMinutes(): void
    {
        // 2026-11-01: America/New_York clocks fall back from 02:00 EDT to 01:00 EST.
        $begin = new \DateTimeImmutable('2026-11-01 00:30:00', new \DateTimeZone('America/New_York'));

        $end = $this->generator->generateEnd($begin, 100);

        // Naive wall-clock addition would say 02:10; the repeated hour means
        // 100 real elapsed minutes only advance the clock face by 40 minutes,
        // landing at 01:10 EST.
        self::assertSame('2026-11-01 01:10:00', $end->format('Y-m-d H:i:s'));
        self::assertSame('-05:00', $end->format('P'));
        self::assertSame(6000, $end->getTimestamp() - $begin->getTimestamp());
    }

    public function testDifferentUserTimezonesProduceIndependentCorrectResults(): void
    {
        $userA = new \DateTimeImmutable('2026-09-03 09:00:00', new \DateTimeZone('America/New_York'));
        $userB = new \DateTimeImmutable('2026-09-03 09:00:00', new \DateTimeZone('Europe/Berlin'));

        $endA = $this->generator->generateEnd($userA, 192);
        $endB = $this->generator->generateEnd($userB, 192);

        self::assertSame('2026-09-03 12:12:00', $endA->format('Y-m-d H:i:s'));
        self::assertSame('America/New_York', $endA->getTimezone()->getName());

        self::assertSame('2026-09-03 12:12:00', $endB->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Berlin', $endB->getTimezone()->getName());

        // Same wall-clock start and duration, different zones: different instants.
        self::assertNotSame($endA->getTimestamp(), $endB->getTimestamp());
    }
}
