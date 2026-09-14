<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Export;

use App\Repository\Query\TimesheetQuery;

trait ChecksMarkAsExported
{
    private function assertDoesNotMarkSourceTimesheets(TimesheetQuery $query): void
    {
        if (\method_exists($query, 'isMarkAsExported') && $query->isMarkAsExported()) {
            throw new \RuntimeException('Compensation-equivalent reports cannot mark source Kimai timesheets as exported.');
        }
    }
}
