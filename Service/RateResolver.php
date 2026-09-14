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
     * @throws UnusableEffectiveRateException when the record carries no usable effective rate
     */
    public function resolveHourlyRate(ExportableItem $item): float
    {
        // Kimai stores rate = fixedRate on fixed-rate records but only writes
        // hourlyRate when non-null, so a stale hourly rate can remain on them.
        // Fixed-rate records therefore never resolve through getHourlyRate().
        if (null !== $item->getFixedRate()) {
            throw UnusableEffectiveRateException::fixedRate($item);
        }

        $hourlyRate = $item->getHourlyRate();

        if (null === $hourlyRate || 0.0 === $hourlyRate) {
            throw UnusableEffectiveRateException::missing($item);
        }

        if ($hourlyRate < 0.0 || !is_finite($hourlyRate)) {
            throw UnusableEffectiveRateException::invalid($item);
        }

        return $hourlyRate;
    }

    /**
     * Whether the record carries a usable effective hourly rate, for callers
     * that need to collect problems rather than abort on the first one.
     */
    public function hasHourlyRate(ExportableItem $item): bool
    {
        try {
            $this->resolveHourlyRate($item);
        } catch (UnusableEffectiveRateException) {
            return false;
        }

        return true;
    }
}
