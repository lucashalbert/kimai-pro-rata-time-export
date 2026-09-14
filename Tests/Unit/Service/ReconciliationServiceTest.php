<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Service;

use App\Entity\ExportableItem;
use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentSummary;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationUserTotal;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationWarning;
use KimaiPlugin\ProRataTimeExportBundle\Model\Money;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->service = new ReconciliationService();
    }

    /**
     * @param CompensationWarning[] $warnings
     */
    private static function record(
        int $id,
        ?User $user,
        string $begin,
        string $end,
        int $actualSeconds,
        int $equivalentMinutes,
        string $actualValue,
        string $equivalentValue,
        array $warnings = [],
    ): CompensationEquivalentRecord {
        $timezone = new \DateTimeZone('UTC');
        $actualStart = new \DateTimeImmutable($begin, $timezone);
        $actualEnd = new \DateTimeImmutable($end, $timezone);

        $record = new CompensationEquivalentRecord(
            $id,
            $user,
            null,
            null,
            null,
            $actualStart,
            $actualEnd,
            $actualSeconds,
            150.0,
            150.0,
            1.0,
            $actualStart,
            $actualStart->modify("+{$equivalentMinutes} minutes"),
            $equivalentMinutes,
            (float) $equivalentMinutes,
            Money::fromDecimalString($actualValue),
            Money::fromDecimalString($equivalentValue),
            Money::fromDecimalString($equivalentValue)->subtract(Money::fromDecimalString($actualValue)),
        );

        foreach ($warnings as $warning) {
            $record = $record->withWarning($warning);
        }

        return $record;
    }

    /**
     * Spec §21 worked example: actual $9,742.00 vs equivalent $9,741.25 is a
     * -$0.75 rounding variance.
     */
    public function testCalculateVarianceReproducesSpecSection21WorkedExample(): void
    {
        $records = [
            self::record(1, null, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 3600, 60, '5000.00', '5000.00'),
            self::record(2, null, '2026-09-01 11:00:00', '2026-09-01 12:00:00', 3600, 60, '4742.00', '4741.25'),
        ];

        self::assertSame(-0.75, $this->service->calculateVariance($records));
    }

    public function testCalculateVarianceIsZeroForAnEmptyRecordSet(): void
    {
        self::assertSame(0.0, $this->service->calculateVariance([]));
    }

    public function testSummarizeAggregatesTotalsPerUserAndDeduplicatesForwardedWarnings(): void
    {
        $alice = new User();
        $alice->setUserIdentifier('alice');
        $bob = new User();
        $bob->setUserIdentifier('bob');

        $sharedWarning = CompensationWarning::runningRecordExcluded(self::fakeItem(999));

        $records = [
            self::record(1, $alice, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 3600, 60, '150.00', '150.00', [$sharedWarning]),
            self::record(2, $alice, '2026-09-02 09:00:00', '2026-09-02 10:30:00', 5400, 90, '225.00', '225.00'),
            self::record(3, $bob, '2026-09-03 09:00:00', '2026-09-03 09:37:00', 2220, 30, '74.00', '75.00', [$sharedWarning]),
        ];

        $summary = $this->service->summarize($records);

        self::assertInstanceOf(CompensationEquivalentSummary::class, $summary);
        self::assertSame(2, $summary->getUserCount());
        self::assertSame(3, $summary->getSourceRecordCount());
        self::assertSame(3600 + 5400 + 2220, $summary->getActualTotalDurationSeconds());
        self::assertSame(60 + 90 + 30, $summary->getEquivalentTotalDurationMinutes());
        self::assertSame('449.00', $summary->getActualCompensationValue()->format());
        self::assertSame('450.00', $summary->getEquivalentCompensationValue()->format());
        self::assertSame('1.00', $summary->getRoundingVariance()->format());
        self::assertSame(150.0, $summary->getBaseRate());
        self::assertSame(CompensationEquivalentSummary::CALCULATION_VERSION, $summary->getCalculationVersion());
        self::assertNotSame('', $summary->getPluginVersion());

        // The same shared warning was attached to two different records but
        // must appear once in the summary.
        self::assertCount(1, $summary->getWarnings());

        $perUser = $summary->getPerUserTotals();
        self::assertCount(2, $perUser);
        $aliceTotal = self::findTotalFor($perUser, $alice);
        self::assertSame(3600 + 5400, $aliceTotal->getActualDurationSeconds());
        self::assertSame(60 + 90, $aliceTotal->getEquivalentDurationMinutes());
    }

    public function testSummarizeReportsMixedBaseRateAsNull(): void
    {
        // record()'s fixture always uses a base rate of 150.0.
        $record150 = self::record(1, null, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 3600, 60, '150.00', '150.00');

        $recordDifferent = new CompensationEquivalentRecord(
            2,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-09-02 09:00:00'),
            new \DateTimeImmutable('2026-09-02 10:00:00'),
            3600,
            135.0,
            135.0,
            1.0,
            new \DateTimeImmutable('2026-09-02 09:00:00'),
            new \DateTimeImmutable('2026-09-02 10:00:00'),
            60,
            60.0,
            Money::fromDecimalString('135.00'),
            Money::fromDecimalString('135.00'),
            Money::fromCents(0),
        );

        $summary = $this->service->summarize([$record150, $recordDifferent]);

        self::assertNull($summary->getBaseRate());
    }

    public function testSummarizeOfEmptyRecordSetIsAllZero(): void
    {
        $summary = $this->service->summarize([]);

        self::assertSame(0, $summary->getUserCount());
        self::assertSame(0, $summary->getSourceRecordCount());
        self::assertSame('0.00', $summary->getActualCompensationValue()->format());
        self::assertSame('0.00', $summary->getEquivalentCompensationValue()->format());
        self::assertSame('0.00', $summary->getRoundingVariance()->format());
        self::assertNull($summary->getReportingPeriodStart());
        self::assertNull($summary->getReportingPeriodEnd());
        self::assertSame([], $summary->getPerUserTotals());
        self::assertSame([], $summary->getWarnings());
    }

    /**
     * @param CompensationUserTotal[] $perUser
     */
    private static function findTotalFor(array $perUser, User $user): CompensationUserTotal
    {
        foreach ($perUser as $total) {
            if ($total->getUser() === $user) {
                return $total;
            }
        }

        self::fail('No per-user total found for the given user.');
    }

    private static function fakeItem(int $id): ExportableItem
    {
        $timesheet = new Timesheet();
        $timesheet->setBegin(new \DateTime('2026-09-01 09:00:00', new \DateTimeZone('UTC')));
        (new \ReflectionProperty(Timesheet::class, 'id'))->setValue($timesheet, $id);

        return $timesheet;
    }
}
