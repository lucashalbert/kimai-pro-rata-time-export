<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

/**
 * Turns an equivalent duration into a start/end interval (spec §11).
 *
 * The only policy in scope for v1 is "preserve the actual start time and
 * shorten the interval toward the end". It lives behind this service so
 * preserve_end / center / distributed (spec §64) can be added later without
 * touching the compensation calculation.
 *
 * Spec §16 and §17: arithmetic happens on timezone-aware date/time objects, never
 * on formatted local time strings, so midnight crossings and DST transitions come
 * out right. The generated end may fall on a later calendar date than the start.
 */
final class IntervalGenerator
{
    /**
     * The compensation-equivalent end timestamp for a record.
     *
     * equivalent_start is always the actual begin (spec §11); this returns
     * begin + equivalentMinutes. A zero equivalent duration returns an instant
     * equal to the start, never an inverted interval (spec §14).
     *
     * @param \DateTimeInterface $actualBegin the unmodified Kimai begin timestamp
     * @param int                $equivalentMinutes whole minutes from DurationScaler
     */
    public function generateEnd(\DateTimeInterface $actualBegin, int $equivalentMinutes): \DateTimeImmutable
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }
}
