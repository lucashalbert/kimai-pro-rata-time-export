<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;

/**
 * One derived compensation-equivalent record, produced from exactly one source
 * Kimai timesheet. Never merged with another (spec §12) and always carrying its
 * source timesheet id (spec §29).
 *
 * The field set is enumerated by spec §19 (review UI) and spec §23 (audit export):
 * user, date, customer, project, activity, actual start/end/duration, effective
 * rate, base rate, factor, equivalent start/end/duration, actual value,
 * equivalent value, rounding difference and source id. The unrounded exact
 * equivalent minutes (spec §10, §21, §47) is also carried, disclosing exactly
 * what minute rounding occurred.
 *
 * Immutable (spec §3.1): every property is readonly and warnings are attached
 * via withWarning(), which returns a new instance rather than mutating this one.
 */
final class CompensationEquivalentRecord
{
    /**
     * @param CompensationWarning[] $warnings
     */
    public function __construct(
        private readonly ?int $sourceTimesheetId,
        private readonly ?User $user,
        private readonly ?Customer $customer,
        private readonly ?Project $project,
        private readonly ?Activity $activity,
        private readonly \DateTimeInterface $actualStart,
        private readonly \DateTimeInterface $actualEnd,
        private readonly int $actualDurationSeconds,
        private readonly float $effectiveHourlyRate,
        private readonly float $baseRate,
        private readonly float $factor,
        private readonly \DateTimeInterface $equivalentStart,
        private readonly \DateTimeInterface $equivalentEnd,
        private readonly int $equivalentDurationMinutes,
        private readonly float $equivalentDurationExactMinutes,
        private readonly Money $actualValue,
        private readonly Money $equivalentValue,
        private readonly Money $roundingDifference,
        private readonly array $warnings = [],
    ) {
    }

    /**
     * A new record with one more warning attached (spec §13, §14, §15, §9);
     * this instance is left unchanged.
     */
    public function withWarning(CompensationWarning $warning): self
    {
        return new self(
            $this->sourceTimesheetId,
            $this->user,
            $this->customer,
            $this->project,
            $this->activity,
            $this->actualStart,
            $this->actualEnd,
            $this->actualDurationSeconds,
            $this->effectiveHourlyRate,
            $this->baseRate,
            $this->factor,
            $this->equivalentStart,
            $this->equivalentEnd,
            $this->equivalentDurationMinutes,
            $this->equivalentDurationExactMinutes,
            $this->actualValue,
            $this->equivalentValue,
            $this->roundingDifference,
            [...$this->warnings, $warning],
        );
    }

    public function getSourceTimesheetId(): ?int
    {
        return $this->sourceTimesheetId;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * The calendar date of the actual start, at midnight (spec §19's "Date" column).
     */
    public function getDate(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->actualStart)->setTime(0, 0, 0);
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function getActualStart(): \DateTimeInterface
    {
        return $this->actualStart;
    }

    public function getActualEnd(): \DateTimeInterface
    {
        return $this->actualEnd;
    }

    public function getActualDurationSeconds(): int
    {
        return $this->actualDurationSeconds;
    }

    public function getEffectiveHourlyRate(): float
    {
        return $this->effectiveHourlyRate;
    }

    public function getBaseRate(): float
    {
        return $this->baseRate;
    }

    public function getFactor(): float
    {
        return $this->factor;
    }

    public function getEquivalentStart(): \DateTimeInterface
    {
        return $this->equivalentStart;
    }

    public function getEquivalentEnd(): \DateTimeInterface
    {
        return $this->equivalentEnd;
    }

    public function getEquivalentDurationMinutes(): int
    {
        return $this->equivalentDurationMinutes;
    }

    /**
     * The unrounded equivalent duration in minutes, before DurationScaler's
     * minute rounding (spec §10, §21, §47). Discloses exactly what rounding
     * occurred, alongside getEquivalentDurationMinutes().
     */
    public function getEquivalentDurationExactMinutes(): float
    {
        return $this->equivalentDurationExactMinutes;
    }

    public function getActualValue(): Money
    {
        return $this->actualValue;
    }

    public function getEquivalentValue(): Money
    {
        return $this->equivalentValue;
    }

    /**
     * Equivalent value minus actual value (spec §19's "Difference" column).
     * Positive means the equivalent representation is worth more than the
     * recorded work (spec §47).
     */
    public function getRoundingDifference(): Money
    {
        return $this->roundingDifference;
    }

    /**
     * @return CompensationWarning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
