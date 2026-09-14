<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentRecord;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationEquivalentSummary;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationUserTotal;
use KimaiPlugin\ProRataTimeExportBundle\Model\CompensationWarning;
use KimaiPlugin\ProRataTimeExportBundle\Model\Money;

/**
 * Proves the derived timecard reconciles against the source records
 * (spec §21, §62).
 *
 * Compares sum(actual duration x effective rate) against
 * sum(equivalent minutes) x base rate and reports the difference. The rounding
 * variance MUST always be surfaced, never suppressed or absorbed (spec §21).
 */
final class ReconciliationService
{
    /**
     * Aggregate derived records into the summary shown before export
     * (spec §20, §48), including per-user totals and the rounding variance in
     * both currency and minutes.
     *
     * The reporting period is derived from the records themselves (earliest
     * actual start to latest actual end): summarize() receives no separate
     * date-range query. The employer base rate metadata is the common rate
     * only when every record resolved to the same one (spec §7 hierarchy can
     * legitimately produce a mix); see CompensationEquivalentSummary::getBaseRate().
     * The generating user is left null here; populating it needs the request's
     * authenticated user, which this records-only signature does not receive.
     *
     * @param CompensationEquivalentRecord[] $records
     */
    public function summarize(array $records): CompensationEquivalentSummary
    {
        $actualSeconds = 0;
        $equivalentMinutes = 0;
        $actualValue = Money::fromCents(0);
        $equivalentValue = Money::fromCents(0);
        $periodStart = null;
        $periodEnd = null;
        $commonBaseRate = null;
        $baseRateVaries = false;
        $warnings = [];
        $userTotals = [];

        foreach ($records as $record) {
            $actualSeconds += $record->getActualDurationSeconds();
            $equivalentMinutes += $record->getEquivalentDurationMinutes();
            $actualValue = $actualValue->add($record->getActualValue());
            $equivalentValue = $equivalentValue->add($record->getEquivalentValue());

            $periodStart = null === $periodStart || $record->getActualStart() < $periodStart
                ? $record->getActualStart()
                : $periodStart;
            $periodEnd = null === $periodEnd || $record->getActualEnd() > $periodEnd
                ? $record->getActualEnd()
                : $periodEnd;

            if (!$baseRateVaries) {
                if (null === $commonBaseRate) {
                    $commonBaseRate = $record->getBaseRate();
                } elseif ($commonBaseRate !== $record->getBaseRate()) {
                    $baseRateVaries = true;
                }
            }

            $userKey = self::userKey($record);
            $userTotals[$userKey] ??= [
                'user' => $record->getUser(),
                'actualSeconds' => 0,
                'equivalentMinutes' => 0,
                'actualValue' => Money::fromCents(0),
                'equivalentValue' => Money::fromCents(0),
            ];
            $userTotals[$userKey]['actualSeconds'] += $record->getActualDurationSeconds();
            $userTotals[$userKey]['equivalentMinutes'] += $record->getEquivalentDurationMinutes();
            $userTotals[$userKey]['actualValue'] = $userTotals[$userKey]['actualValue']->add($record->getActualValue());
            $userTotals[$userKey]['equivalentValue'] = $userTotals[$userKey]['equivalentValue']->add($record->getEquivalentValue());

            foreach ($record->getWarnings() as $warning) {
                if (!self::containsWarning($warnings, $warning)) {
                    $warnings[] = $warning;
                }
            }
        }

        $perUserTotals = \array_values(\array_map(
            static fn (array $total): CompensationUserTotal => new CompensationUserTotal(
                $total['user'],
                $total['actualSeconds'],
                $total['equivalentMinutes'],
                $total['actualValue'],
                $total['equivalentValue'],
            ),
            $userTotals
        ));

        return new CompensationEquivalentSummary(
            $periodStart,
            $periodEnd,
            \count($userTotals),
            \count($records),
            $actualSeconds,
            $equivalentMinutes,
            $actualValue,
            $equivalentValue,
            $equivalentValue->subtract($actualValue),
            $perUserTotals,
            $warnings,
            self::pluginVersion(),
            new \DateTimeImmutable(),
            $baseRateVaries ? null : $commonBaseRate,
            null,
        );
    }

    /**
     * Total rounding variance in currency: equivalent compensation minus actual
     * compensation. Positive means the equivalent representation is worth more
     * than the recorded work.
     *
     * @param CompensationEquivalentRecord[] $records
     */
    public function calculateVariance(array $records): float
    {
        $variance = Money::fromCents(0);

        foreach ($records as $record) {
            $variance = $variance->add($record->getEquivalentValue()->subtract($record->getActualValue()));
        }

        return (float) $variance->format();
    }

    /**
     * @param CompensationWarning[] $warnings
     */
    private static function containsWarning(array $warnings, CompensationWarning $candidate): bool
    {
        foreach ($warnings as $warning) {
            if ($warning->equals($candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function userKey(CompensationEquivalentRecord $record): string
    {
        $user = $record->getUser();

        return null !== $user ? 'id:' . ($user->getId() ?? \spl_object_id($user)) : 'unknown';
    }

    /**
     * Spec §61: plugin version, read from composer.json the same way Kimai's
     * own PluginMetadata resolves it (extra.kimai.version, falling back to the
     * root version, then "unknown") rather than duplicating the value in a
     * constant that could drift out of sync.
     */
    private static function pluginVersion(): string
    {
        $composer = \json_decode(
            \file_get_contents(\dirname(__DIR__) . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        return $composer['extra']['kimai']['version'] ?? $composer['version'] ?? 'unknown';
    }
}
