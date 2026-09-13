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
 * One derived compensation-equivalent record, produced from exactly one source
 * Kimai timesheet. Never merged with another (spec §12) and always carrying its
 * source timesheet id (spec §29).
 *
 * The field set is enumerated by spec §19 (review UI) and spec §23 (audit export):
 * user, date, customer, project, activity, actual start/end/duration, effective
 * rate, base rate, factor, equivalent start/end/duration, actual value,
 * equivalent value, rounding difference and source id.
 *
 * TODO(follow-up): implemented in a later task
 */
final class CompensationEquivalentRecord
{
}
