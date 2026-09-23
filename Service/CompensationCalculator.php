<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

use App\Entity\ExportableItem;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationCalculationResult;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationWarning;
use KimaiPlugin\ProRataTimeExportBundle\Model\Money;

/**
 * The compensation domain entry point (spec §67): composes RateResolver,
 * DurationScaler and IntervalGenerator into derived records.
 *
 * Strictly read-only (spec §3.1): never writes to, re-queries or flushes a
 * Timesheet. Deterministic (spec §3.3): no dependency on the current time.
 * One derived record per source record; never merged, overlaps never resolved
 * (spec §12, §13).
 */
final class CompensationCalculator
{
    private const SECONDS_PER_HOUR = 3600;
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly RateResolver $rateResolver,
        private readonly DurationScaler $durationScaler,
        private readonly IntervalGenerator $intervalGenerator,
        private readonly CompensationConfiguration $configuration,
    ) {
    }

    /**
     * Assumes a completed record; calculateAll() excludes running ones (spec §15).
     *
     * @throws CompensationUnavailableException on a missing or invalid effective rate (spec §6, §32),
     *                                          or a missing/invalid employer base rate (spec §7, §32)
     * @throws \LogicException    if called directly with a running record
     */
    public function calculate(ExportableItem $item): CompensationEquivalentRecord
    {
        $actualBegin = $item->getBegin();
        $actualEnd = $item->getEnd();

        if (null === $actualEnd) {
            throw new \LogicException(\sprintf(
                'Timesheet #%s is currently running; CompensationCalculator::calculate() requires a completed record.',
                $item->getId() ?? '(unsaved)'
            ));
        }

        // Effective/base rate resolution fails the export rather than guessing
        // (spec §6, §7, §32): both exceptions are allowed to propagate.
        $effectiveHourlyRate = $this->rateResolver->resolveHourlyRate($item);
        $baseRate = $this->configuration->getBaseRate($item);

        $actualSeconds = $item->getDuration() ?? 0;

        $factor = $this->durationScaler->calculateFactor($effectiveHourlyRate, $baseRate);
        $equivalentMinutes = $this->durationScaler->scaleToMinutes($actualSeconds, $effectiveHourlyRate, $baseRate);
        $exactEquivalentMinutes = $this->durationScaler->scaleToExactMinutes($actualSeconds, $effectiveHourlyRate, $baseRate);
        $equivalentEnd = $this->intervalGenerator->generateEnd($actualBegin, $equivalentMinutes);

        $actualValue = self::rateAsMoney($effectiveHourlyRate)->multiplyByRatio($actualSeconds, self::SECONDS_PER_HOUR);
        $equivalentValue = self::rateAsMoney($baseRate)->multiplyByRatio($equivalentMinutes, self::SECONDS_PER_MINUTE);
        $roundingDifference = $equivalentValue->subtract($actualValue);

        $record = new CompensationEquivalentRecord(
            $item->getId(),
            $item->getUser(),
            $item->getProject()?->getCustomer(),
            $item->getProject(),
            $item->getActivity(),
            $actualBegin,
            $actualEnd,
            $actualSeconds,
            $effectiveHourlyRate,
            $baseRate,
            $factor,
            $actualBegin,
            $equivalentEnd,
            $equivalentMinutes,
            $exactEquivalentMinutes,
            $actualValue,
            $equivalentValue,
            $roundingDifference,
        );

        if (0 === $actualSeconds) {
            $record = $record->withWarning(CompensationWarning::zeroDurationRecord($item));
        }

        $wallClockWorkedSeconds = $actualEnd->getTimestamp() - $actualBegin->getTimestamp() - $item->getBreak();

        if ($wallClockWorkedSeconds !== $actualSeconds) {
            $record = $record->withWarning(
                CompensationWarning::durationTimestampMismatch($item, $actualSeconds, $wallClockWorkedSeconds)
            );
        }

        return $record;
    }

    /**
     * Running records are excluded with a warning (spec §15); zero-duration
     * records are preserved with a zero-length interval (spec §14). Running-record
     * warnings are batch-level because no derived record exists to carry them.
     *
     * @param ExportableItem[] $items the array Kimai's export pipeline provides,
     *                                already filtered and permission-scoped (spec §18, §31)
     */
    public function calculateAll(array $items): CompensationCalculationResult
    {
        $records = [];
        $survivingItems = [];
        $runningWarnings = [];

        foreach ($items as $item) {
            if (null === $item->getEnd()) {
                $runningWarnings[] = CompensationWarning::runningRecordExcluded($item);
                continue;
            }

            $records[] = $this->calculate($item);
            $survivingItems[] = $item;
        }

        $records = self::attachOverlapWarnings($records, $survivingItems);

        return new CompensationCalculationResult($records, $runningWarnings);
    }

    /**
     * Pairwise overlap comparison across the batch (spec §13): no interval
     * tree, export batches are not large enough to need one.
     *
     * @param CompensationEquivalentRecord[] $records
     * @param ExportableItem[]               $items   parallel to $records: $items[$i] produced $records[$i]
     *
     * @return CompensationEquivalentRecord[]
     */
    private static function attachOverlapWarnings(array $records, array $items): array
    {
        $count = \count($items);

        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (!self::sameUser($items[$i], $items[$j]) || !self::overlaps($items[$i], $items[$j])) {
                    continue;
                }

                $records[$i] = $records[$i]->withWarning(CompensationWarning::overlappingSourceRecords($items[$i], $items[$j]));
                $records[$j] = $records[$j]->withWarning(CompensationWarning::overlappingSourceRecords($items[$j], $items[$i]));
            }
        }

        return $records;
    }

    private static function overlaps(ExportableItem $a, ExportableItem $b): bool
    {
        return $a->getBegin() < $b->getEnd() && $b->getBegin() < $a->getEnd();
    }

    private static function sameUser(ExportableItem $a, ExportableItem $b): bool
    {
        $userA = $a->getUser();
        $userB = $b->getUser();

        if ($userA === $userB) {
            return true;
        }

        if (null === $userA || null === $userB) {
            return false;
        }

        $idA = $userA->getId();
        $idB = $userB->getId();

        if (null !== $idA && null !== $idB) {
            return $idA === $idB;
        }

        return '' !== $userA->getUserIdentifier() && $userA->getUserIdentifier() === $userB->getUserIdentifier();
    }

    /**
     * An hourly rate as Money, so actual/equivalent compensation values are
     * computed with exact rational arithmetic (multiplyByRatio) rather than
     * binary floats (spec §27). Rates are currency amounts, so 6 decimal
     * digits (Money's own precision ceiling) is always exact in practice.
     */
    private static function rateAsMoney(float $hourlyRate): Money
    {
        return Money::fromDecimalString(\sprintf('%.6F', $hourlyRate));
    }
}
