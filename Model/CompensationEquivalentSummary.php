<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

use App\Entity\User;

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
 * duration/timestamp disagreement (§9). ReconciliationService::summarize()
 * forwards every warning already attached to the input records, de-duplicated.
 *
 * Calculation versioning (spec §60): CALCULATION_VERSION starts at 1 for this
 * initial rounding/scaling algorithm (nearest-whole-minute, half up, via
 * DurationScaler; preserve-start interval policy, via IntervalGenerator).
 * Bump it, and only it, whenever that algorithm changes, so a historical report
 * remains interpretable against the version that produced it.
 *
 * "Base rate" metadata (spec §61) predates the spec §7 per-record hierarchy: a
 * report may now legitimately mix records resolved through different override
 * levels. getBaseRate() therefore reports the common rate only when every
 * record in the report resolved to the same one, and null when the report
 * mixes rates — the authoritative per-record value is always available via
 * CompensationEquivalentRecord::getBaseRate().
 *
 * "Generating user" (spec §61) is not resolvable from a records array alone;
 * ReconciliationService::summarize() has no request/session
 * context. It is left null at this phase and is a later task's responsibility
 * to populate (from the export controller's authenticated user).
 */
final class CompensationEquivalentSummary
{
    public const CALCULATION_VERSION = 1;

    /**
     * @param CompensationUserTotal[] $perUserTotals
     * @param CompensationWarning[]   $warnings
     */
    public function __construct(
        private readonly ?\DateTimeInterface $reportingPeriodStart,
        private readonly ?\DateTimeInterface $reportingPeriodEnd,
        private readonly int $userCount,
        private readonly int $sourceRecordCount,
        private readonly int $actualTotalDurationSeconds,
        private readonly int $equivalentTotalDurationMinutes,
        private readonly Money $actualCompensationValue,
        private readonly Money $equivalentCompensationValue,
        private readonly Money $roundingVariance,
        private readonly array $perUserTotals,
        private readonly array $warnings,
        private readonly string $pluginVersion,
        private readonly \DateTimeImmutable $generatedAt,
        private readonly ?float $baseRate,
        private readonly ?User $generatedByUser,
    ) {
    }

    public function getReportingPeriodStart(): ?\DateTimeInterface
    {
        return $this->reportingPeriodStart;
    }

    public function getReportingPeriodEnd(): ?\DateTimeInterface
    {
        return $this->reportingPeriodEnd;
    }

    public function getUserCount(): int
    {
        return $this->userCount;
    }

    public function getSourceRecordCount(): int
    {
        return $this->sourceRecordCount;
    }

    public function getActualTotalDurationSeconds(): int
    {
        return $this->actualTotalDurationSeconds;
    }

    public function getEquivalentTotalDurationMinutes(): int
    {
        return $this->equivalentTotalDurationMinutes;
    }

    public function getActualCompensationValue(): Money
    {
        return $this->actualCompensationValue;
    }

    public function getEquivalentCompensationValue(): Money
    {
        return $this->equivalentCompensationValue;
    }

    /**
     * Equivalent compensation minus actual compensation (spec §21). Never
     * suppressed: always present, even when zero.
     */
    public function getRoundingVariance(): Money
    {
        return $this->roundingVariance;
    }

    /**
     * @return CompensationUserTotal[]
     */
    public function getPerUserTotals(): array
    {
        return $this->perUserTotals;
    }

    /**
     * @return CompensationWarning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getPluginVersion(): string
    {
        return $this->pluginVersion;
    }

    public function getCalculationVersion(): int
    {
        return self::CALCULATION_VERSION;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /**
     * The employer base rate used, or null when records in this report
     * resolved to different rates through the spec §7 hierarchy.
     */
    public function getBaseRate(): ?float
    {
        return $this->baseRate;
    }

    public function getGeneratedByUser(): ?User
    {
        return $this->generatedByUser;
    }
}
