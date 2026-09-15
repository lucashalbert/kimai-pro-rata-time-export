<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Export;

use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;

/**
 * Deterministic row/header formatting shared by the employer, audit and XLSX
 * exporters (spec §22, §23, §50), so the three output formats can never
 * disagree about how a derived record is rendered.
 *
 * Actual/Equivalent Start and End use a full `Y-m-d H:i` timestamp rather than
 * a bare time, unlike the employer CSV's `H:i`-only columns: the audit trail
 * must stay unambiguous for midnight-crossing records (spec §16), where the
 * Date column alone cannot disambiguate which calendar day an End time falls
 * on.
 */
trait CompensationRowFormatter
{
    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private static function employerRow(CompensationEquivalentRecord $record): array
    {
        return [
            $record->getDate()->format('Y-m-d'),
            $record->getUser()?->getDisplayName() ?? '',
            $record->getProject()?->getName() ?? '',
            $record->getEquivalentStart()->format('H:i'),
            $record->getEquivalentEnd()->format('H:i'),
        ];
    }

    /**
     * @return string[]
     */
    private static function employerHeader(): array
    {
        return ['Date', 'User', 'Project', 'Start', 'End'];
    }

    /**
     * @return string[]
     */
    private static function auditRow(CompensationEquivalentRecord $record): array
    {
        return [
            (string) ($record->getSourceTimesheetId() ?? ''),
            $record->getUser()?->getDisplayName() ?? '',
            $record->getDate()->format('Y-m-d'),
            $record->getCustomer()?->getName() ?? '',
            $record->getProject()?->getName() ?? '',
            $record->getActivity()?->getName() ?? '',
            $record->getActualStart()->format('Y-m-d H:i'),
            $record->getActualEnd()->format('Y-m-d H:i'),
            self::formatDurationMinutes(\intdiv($record->getActualDurationSeconds(), 60)),
            self::formatRate($record->getEffectiveHourlyRate()),
            self::formatRate($record->getBaseRate()),
            self::formatFactor($record->getFactor()),
            $record->getEquivalentStart()->format('Y-m-d H:i'),
            $record->getEquivalentEnd()->format('Y-m-d H:i'),
            self::formatDurationMinutes($record->getEquivalentDurationMinutes()),
            $record->getActualValue()->format(),
            $record->getEquivalentValue()->format(),
            $record->getRoundingDifference()->format(),
        ];
    }

    /**
     * @return string[]
     */
    private static function auditHeader(): array
    {
        return [
            'Source Timesheet ID', 'User', 'Date', 'Customer', 'Project', 'Activity',
            'Actual Start', 'Actual End', 'Actual Duration',
            'Effective Rate', 'Employer Base Rate', 'Conversion Factor',
            'Equivalent Start', 'Equivalent End', 'Equivalent Duration',
            'Actual Compensation', 'Equivalent Compensation', 'Rounding Difference',
        ];
    }

    private static function formatDurationMinutes(int $totalMinutes): string
    {
        return \sprintf('%d:%02d', \intdiv($totalMinutes, 60), $totalMinutes % 60);
    }

    private static function formatRate(float $rate): string
    {
        return \sprintf('%.2f', $rate);
    }

    private static function formatFactor(float $factor): string
    {
        return \sprintf('%.6f', $factor);
    }
}
