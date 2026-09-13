<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

/**
 * Aggregate view over a set of CompensationEquivalentRecord instances, plus the
 * warnings collected while building them.
 *
 * Spec §20 and §48 enumerate the contents: reporting period, user count, source
 * record count, actual total time, equivalent total time, actual compensation
 * value, equivalent compensation value, rounding variance and per-user totals.
 * Spec §21 requires the rounding variance to be reported, never hidden. Spec §61
 * additionally requires report metadata (plugin version, calculation version,
 * generation timestamp, base rate, generating user).
 *
 * Warnings that must survive to the summary: excluded running records (§15),
 * overlapping source records (§13), zero-duration records (§14) and
 * duration/timestamp disagreement (§9).
 *
 * TODO(follow-up): implemented in a later task
 */
final class CompensationEquivalentSummary
{
}
