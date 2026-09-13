<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentSummary;

/**
 * Proves the derived timecard reconciles against the source records
 * (spec §21, §62).
 *
 * Compares sum(actual duration x effective rate) against
 * sum(equivalent minutes) x base rate and reports the difference. The rounding
 * variance MUST always be surfaced, never suppressed or absorbed (spec §21).
 */
final class ReconciliationService
{
    /**
     * Aggregate derived records into the summary shown before export
     * (spec §20, §48), including per-user totals and the rounding variance in
     * both currency and minutes.
     *
     * @param CompensationEquivalentRecord[] $records
     */
    public function summarize(array $records): CompensationEquivalentSummary
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }

    /**
     * Total rounding variance in currency: equivalent compensation minus actual
     * compensation. Positive means the equivalent representation is worth more
     * than the recorded work.
     *
     * @param CompensationEquivalentRecord[] $records
     */
    public function calculateVariance(array $records): float
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }
}
