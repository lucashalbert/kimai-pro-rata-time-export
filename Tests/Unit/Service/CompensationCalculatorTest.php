<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Service;

use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationWarningReason;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationCalculator;
use KimaiPlugin\ProRataTimeExportBundle\Service\DurationScaler;
use KimaiPlugin\ProRataTimeExportBundle\Service\IntervalGenerator;
use KimaiPlugin\ProRataTimeExportBundle\Service\RateResolver;
use KimaiPlugin\ProRataTimeExportBundle\Service\UnusableEffectiveRateException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against Kimai's real entities (no database), same bootstrap as
 * Tests/Unit/Service/RateResolverTest.php.
 *
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §46, §47 (worked examples),
 *      §13-§15 (overlap/running/zero-duration), §9 (duration/timestamp disagreement)
 */
final class CompensationCalculatorTest extends TestCase
{
    private CompensationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CompensationCalculator(
            new RateResolver(),
            new DurationScaler(),
            new IntervalGenerator(),
            self::configuration(150.0)
        );
    }

    private static function configuration(float $globalBaseRate): CompensationConfiguration
    {
        $loader = new class implements ConfigLoaderInterface {
            public function getConfigurations(): array
            {
                return [];
            }
        };

        return new CompensationConfiguration(new SystemConfiguration(
            $loader,
            ['pro_rata_time_export.base_rate' => $globalBaseRate]
        ));
    }

    /**
     * @param int $id     a distinct id per fixture keeps warning messages/assertions unambiguous
     */
    private static function timesheet(
        string $begin,
        ?string $end,
        int $duration,
        ?float $hourlyRate,
        int $id,
        int $break = 0,
        ?Project $project = null,
        ?User $user = null,
    ): Timesheet {
        $timezone = new \DateTimeZone('UTC');
        $record = new Timesheet();
        $record->setBegin(new \DateTime($begin, $timezone));
        if (null !== $end) {
            $record->setEnd(new \DateTime($end, $timezone));
        }
        $record->setDuration($duration);
        $record->setBreak($break);
        $record->setHourlyRate($hourlyRate);
        $record->setRate(($hourlyRate ?? 0.0) * ($duration / 3600));
        $record->setProject($project ?? (new Project())->setName('Project'));
        if (null !== $user) {
            $record->setUser($user);
        }
        (new \ReflectionProperty(Timesheet::class, 'id'))->setValue($record, $id);

        return $record;
    }

    /**
     * Spec §46: 180 min @ $150/hr (factor 1.0) and 240 min @ $120/hr (factor 0.8)
     * against a $150 base rate reconcile exactly, with zero variance.
     */
    public function testEndToEndExampleFromSpecSection46(): void
    {
        $projectA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 12:00:00', 180 * 60, 150.0, id: 1);
        $projectB = self::timesheet('2026-09-03 13:00:00', '2026-09-03 17:00:00', 240 * 60, 120.0, id: 2);

        $recordA = $this->calculator->calculate($projectA);
        $recordB = $this->calculator->calculate($projectB);

        self::assertSame(180, $recordA->getEquivalentDurationMinutes());
        self::assertSame('12:00:00', $recordA->getEquivalentEnd()->format('H:i:s'));
        self::assertSame('450.00', $recordA->getActualValue()->format());
        self::assertSame('450.00', $recordA->getEquivalentValue()->format());

        self::assertSame(192, $recordB->getEquivalentDurationMinutes());
        self::assertSame('16:12:00', $recordB->getEquivalentEnd()->format('H:i:s'));
        self::assertSame('480.00', $recordB->getActualValue()->format());
        self::assertSame('480.00', $recordB->getEquivalentValue()->format());

        self::assertSame('0.00', $recordA->getRoundingDifference()->format());
        self::assertSame('0.00', $recordB->getRoundingDifference()->format());
    }

    /**
     * Spec §47: 37 minutes @ $120/hr against a $150 base rate rounds
     * 29.6 -> 30 minutes and discloses a +$1.00 rounding difference.
     */
    public function testEndToEndExampleFromSpecSection47WithRounding(): void
    {
        $item = self::timesheet('2026-09-03 09:17:00', '2026-09-03 09:54:00', 37 * 60, 120.0, id: 3);

        $record = $this->calculator->calculate($item);

        self::assertSame(30, $record->getEquivalentDurationMinutes());
        self::assertEqualsWithDelta(29.6, $record->getEquivalentDurationExactMinutes(), 1e-9);
        self::assertSame('09:17:00', $record->getEquivalentStart()->format('H:i:s'));
        self::assertSame('09:47:00', $record->getEquivalentEnd()->format('H:i:s'));
        self::assertSame('74.00', $record->getActualValue()->format());
        self::assertSame('75.00', $record->getEquivalentValue()->format());
        self::assertSame('1.00', $record->getRoundingDifference()->format());
        self::assertSame(3, $item->getId());
        self::assertSame(3, $record->getSourceTimesheetId());
    }

    public function testCalculateAllExcludesARunningRecordWithAWarningOnTheSurvivingRecords(): void
    {
        $completed = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 10);
        $running = self::timesheet('2026-09-03 11:00:00', null, 0, 150.0, id: 11);

        $records = $this->calculator->calculateAll([$completed, $running]);

        self::assertCount(1, $records);
        self::assertSame(10, $records[0]->getSourceTimesheetId());

        $warnings = $records[0]->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(CompensationWarningReason::RUNNING_RECORD_EXCLUDED, $warnings[0]->reason);
        self::assertSame(11, $warnings[0]->sourceTimesheetId);
    }

    public function testZeroDurationRecordIsPreservedWithAZeroLengthEquivalentIntervalAndWarning(): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 09:00:00', 0, 120.0, id: 20);

        $record = $this->calculator->calculate($item);

        self::assertSame(0, $record->getActualDurationSeconds());
        self::assertSame(0, $record->getEquivalentDurationMinutes());
        self::assertEquals($record->getEquivalentStart(), $record->getEquivalentEnd());
        self::assertSame('09:00:00', $record->getEquivalentEnd()->format('H:i:s'));

        $warnings = $record->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(CompensationWarningReason::ZERO_DURATION_RECORD, $warnings[0]->reason);
    }

    /**
     * Spec §13/§42: overlapping source records are each transformed
     * independently, with no merging/deduplication, plus a warning on each.
     */
    public function testOverlappingSourceRecordsAreEachTransformedIndependentlyWithAWarning(): void
    {
        $a = self::timesheet('2026-09-03 09:00:00', '2026-09-03 11:00:00', 2 * 3600, 150.0, id: 30);
        $b = self::timesheet('2026-09-03 10:00:00', '2026-09-03 12:00:00', 2 * 3600, 150.0, id: 31);

        $records = $this->calculator->calculateAll([$a, $b]);

        self::assertCount(2, $records);
        self::assertSame(120, $records[0]->getEquivalentDurationMinutes());
        self::assertSame(120, $records[1]->getEquivalentDurationMinutes());

        $warningsA = $records[0]->getWarnings();
        self::assertCount(1, $warningsA);
        self::assertSame(CompensationWarningReason::OVERLAPPING_SOURCE_RECORDS, $warningsA[0]->reason);
        self::assertSame(30, $warningsA[0]->sourceTimesheetId);
        self::assertStringContainsString('#31', $warningsA[0]->message);

        $warningsB = $records[1]->getWarnings();
        self::assertCount(1, $warningsB);
        self::assertSame(CompensationWarningReason::OVERLAPPING_SOURCE_RECORDS, $warningsB[0]->reason);
        self::assertSame(31, $warningsB[0]->sourceTimesheetId);
        self::assertStringContainsString('#30', $warningsB[0]->message);
    }

    public function testNonOverlappingRecordsProduceNoOverlapWarning(): void
    {
        $a = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 40);
        $b = self::timesheet('2026-09-03 10:00:00', '2026-09-03 11:00:00', 3600, 150.0, id: 41);

        $records = $this->calculator->calculateAll([$a, $b]);

        self::assertCount(0, $records[0]->getWarnings());
        self::assertCount(0, $records[1]->getWarnings());
    }

    /**
     * Spec §9: the stored duration and the begin/end/break wall-clock
     * difference can legitimately disagree (Kimai rounding); the export must
     * disclose that rather than silently pick one.
     */
    public function testDurationTimestampDisagreementProducesAWarning(): void
    {
        // Wall-clock is exactly 60 minutes, but the stored duration is 59.
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 59 * 60, 150.0, id: 50);

        $record = $this->calculator->calculate($item);

        self::assertSame(59 * 60, $record->getActualDurationSeconds());

        $warnings = $record->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(CompensationWarningReason::DURATION_TIMESTAMP_MISMATCH, $warnings[0]->reason);
        self::assertSame(50, $warnings[0]->sourceTimesheetId);
    }

    public function testDurationMatchingWallClockProducesNoMismatchWarning(): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 51);

        $record = $this->calculator->calculate($item);

        self::assertCount(0, $record->getWarnings());
    }

    public function testDurationMatchingWallClockMinusBreakProducesNoMismatchWarning(): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3300, 150.0, id: 52, break: 300);

        $record = $this->calculator->calculate($item);

        self::assertCount(0, $record->getWarnings());
    }

    public function testUnusableEffectiveRatePropagatesOutOfCalculate(): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, null, id: 60);

        $this->expectException(UnusableEffectiveRateException::class);

        $this->calculator->calculate($item);
    }

    public function testUnusableEffectiveRatePropagatesOutOfCalculateAllRatherThanBeingCaught(): void
    {
        $good = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 61);
        $badRate = self::timesheet('2026-09-03 11:00:00', '2026-09-03 12:00:00', 3600, 0.0, id: 62);

        $this->expectException(UnusableEffectiveRateException::class);

        $this->calculator->calculateAll([$good, $badRate]);
    }

    public function testMissingBaseRatePropagatesAsRuntimeException(): void
    {
        $calculator = new CompensationCalculator(
            new RateResolver(),
            new DurationScaler(),
            new IntervalGenerator(),
            self::unconfigured()
        );
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 70);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Employer base rate is not configured.');

        $calculator->calculate($item);
    }

    private static function unconfigured(): CompensationConfiguration
    {
        $loader = new class implements ConfigLoaderInterface {
            public function getConfigurations(): array
            {
                return [];
            }
        };

        return new CompensationConfiguration(new SystemConfiguration($loader, []));
    }

    public function testCallingCalculateDirectlyOnARunningRecordThrows(): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', null, 0, 150.0, id: 80);

        $this->expectException(\LogicException::class);

        $this->calculator->calculate($item);
    }
}
