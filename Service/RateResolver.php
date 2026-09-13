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

/**
 * Resolves the effective hourly rate that was in force for a source record
 * (spec §6).
 *
 * MUST read the rate frozen onto the record itself — ExportableItem::getHourlyRate(),
 * with getRate() and getFixedRate() as context. MUST NOT consult
 * App\Timesheet\RateService, TimesheetRepository::findMatchingRates(), or any
 * *RateRepository: those resolve the *current* customer/project/activity/user
 * rate hierarchy, which has no historical dimension and would silently
 * misprice older records. Spec §40 makes this a regression test.
 *
 * @see docs/kimai-version-notes.md §2
 */
final class RateResolver
{
    /**
     * The effective hourly compensation rate associated with the source record.
     *
     * Spec §6 and §45: a null, zero or otherwise invalid rate is an error. The
     * default behaviour is to fail the export with a clear message (spec §32),
     * never to substitute the current project rate.
     *
     * @throws \RuntimeException when the record carries no usable effective rate
     */
    public function resolveHourlyRate(ExportableItem $item): float
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }

    /**
     * Whether the record carries a usable effective hourly rate, for callers
     * that need to collect problems rather than abort on the first one.
     */
    public function hasHourlyRate(ExportableItem $item): bool
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }
}
