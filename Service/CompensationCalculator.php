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
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;

/**
 * The compensation domain entry point (spec §67): composes RateResolver,
 * DurationScaler and IntervalGenerator into derived records.
 *
 * Strictly read-only (spec §3.1). It reads the ExportableItem instances Kimai's
 * export pipeline hands to a renderer and never writes to, re-queries or flushes
 * a Timesheet. Deterministic (spec §3.3): no randomness, no dependency on the
 * current time, no order-dependent state.
 *
 * Each source record produces exactly one derived record; records are never
 * merged and overlaps are never resolved (spec §12, §13).
 */
final class CompensationCalculator
{
    public function __construct(
        private readonly RateResolver $rateResolver,
        private readonly DurationScaler $durationScaler,
        private readonly IntervalGenerator $intervalGenerator,
        private readonly CompensationConfiguration $configuration,
    ) {
    }

    /**
     * Derive one compensation-equivalent record from one source record.
     *
     * @throws \RuntimeException on a missing or invalid effective rate (spec §6, §32)
     */
    public function calculate(ExportableItem $item): CompensationEquivalentRecord
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }

    /**
     * Derive records for a whole filtered result set.
     *
     * Running records are excluded with a warning rather than converted
     * (spec §15); zero-duration records are preserved with a zero-length
     * equivalent interval (spec §14). Warnings are collected on the returned
     * records/summary rather than being logged and dropped (spec §32, §33).
     *
     * @param ExportableItem[] $items the array Kimai's export pipeline provides,
     *                                already filtered and permission-scoped (spec §18, §31)
     *
     * @return CompensationEquivalentRecord[]
     */
    public function calculateAll(array $items): array
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }
}
