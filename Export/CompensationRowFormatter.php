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
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentSummary;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationWarning;

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

    /**
     * @param CompensationWarning[] $warnings
     *
     * @return list<list<string>>
     */
    private static function warningRows(array $warnings): array
    {
        if ([] === $warnings) {
            return [];
        }

        return [
            [' '],
            ['Warnings'],
            ...\array_map(static fn (CompensationWarning $warning): array => [$warning->message], $warnings),
        ];
    }

    /**
     * @return list<list<string>>
     */
    private static function summaryRows(CompensationEquivalentSummary $summary): array
    {
        $rows = [
            ['Summary'],
            ['Reporting Period', self::formatPeriod($summary)],
            ['Users', (string) $summary->getUserCount()],
            ['Source Records', (string) $summary->getSourceRecordCount()],
            ['Actual Recorded Time', self::formatDurationMinutes(\intdiv($summary->getActualTotalDurationSeconds(), 60))],
            ['Actual Compensation Value', $summary->getActualCompensationValue()->format()],
            ['Compensation Equivalent Time', self::formatDurationMinutes($summary->getEquivalentTotalDurationMinutes())],
            ['Equivalent Compensation Value', $summary->getEquivalentCompensationValue()->format()],
            ['Employer Base Rate', null !== $summary->getBaseRate() ? self::formatRate($summary->getBaseRate()) . '/hr' : '(varies by record)'],
            ['Rounding Variance', $summary->getRoundingVariance()->format()],
            ['Plugin Version', $summary->getPluginVersion()],
            ['Calculation Version', (string) $summary->getCalculationVersion()],
            ['Generated At', $summary->getGeneratedAt()->format('Y-m-d H:i')],
            [' '],
            ['Per-User Totals'],
            ['User', 'Actual', 'Actual Value', 'Equivalent', 'Equivalent Value'],
        ];

        foreach ($summary->getPerUserTotals() as $userTotal) {
            $rows[] = [
                $userTotal->getUser()?->getDisplayName() ?? '(unknown)',
                self::formatDurationMinutes(\intdiv($userTotal->getActualDurationSeconds(), 60)),
                $userTotal->getActualValue()->format(),
                self::formatDurationMinutes($userTotal->getEquivalentDurationMinutes()),
                $userTotal->getEquivalentValue()->format(),
            ];
        }

        return [
            ...$rows,
            ...self::warningRows($summary->getWarnings()),
        ];
    }

    private static function formatPeriod(CompensationEquivalentSummary $summary): string
    {
        $start = $summary->getReportingPeriodStart();
        $end = $summary->getReportingPeriodEnd();

        if (null === $start || null === $end) {
            return '(not specified)';
        }

        return $start->format('Y-m-d') . ' - ' . $end->format('Y-m-d');
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
