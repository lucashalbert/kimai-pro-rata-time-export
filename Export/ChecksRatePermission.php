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
 * Gates a rate-bearing view/export on the same permission Kimai's own export
 * column rendering uses (spec §18, §31): `App\Export\ColumnConverter::isRenderRate()`
 * decides per-request whether rate columns are even shown, using
 * `view_rate_own_timesheet` when the export is scoped to a single user and
 * `view_rate_other_timesheet` otherwise. This mirrors that exact rule rather
 * than inventing a new permission.
 *
 * Unlike ColumnConverter (which silently drops rate columns), a renderer using
 * this trait denies the whole request when the permission is missing: every
 * column in the review/audit/reconciliation output is rate-derived, so a
 * redacted version would be near-empty and risks a template omission
 * silently leaking a rate value instead.
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
