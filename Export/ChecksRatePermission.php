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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Mirrors `App\Export\ColumnConverter::isRenderRate()` (spec §18, §31):
 * `view_rate_own_timesheet` when the export is scoped to a single user,
 * `view_rate_other_timesheet` otherwise.
 *
 * Unlike ColumnConverter (which silently drops rate columns), this denies the
 * whole request: every column here is rate-derived, so a redacted version
 * would be near-empty and risks a template omission leaking a rate.
 */
trait ChecksRatePermission
{
    private function assertRateVisible(Security $security, TimesheetQuery $query): void
    {
        if (null === $security->getUser()) {
            // No authenticated user to check (e.g. a console context) mirrors
            // ColumnConverter::isRenderRate()'s own carve-out.
            return;
        }

        $permission = null !== $query->getUser() ? 'view_rate_own_timesheet' : 'view_rate_other_timesheet';

        if (!$security->isGranted($permission)) {
            throw new AccessDeniedException('You are not permitted to view compensation rate data.');
        }
    }
}
