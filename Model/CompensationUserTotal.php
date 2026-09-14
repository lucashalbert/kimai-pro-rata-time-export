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
 * One row of CompensationEquivalentSummary's per-user breakdown (spec §20, §48):
 * a user's actual and compensation-equivalent totals across their source
 * records in the report.
 */
final class CompensationUserTotal
{
    public function __construct(
        private readonly ?User $user,
        private readonly int $actualDurationSeconds,
        private readonly int $equivalentDurationMinutes,
        private readonly Money $actualValue,
        private readonly Money $equivalentValue,
    ) {
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getActualDurationSeconds(): int
    {
        return $this->actualDurationSeconds;
    }

    public function getEquivalentDurationMinutes(): int
    {
        return $this->equivalentDurationMinutes;
    }

    public function getActualValue(): Money
    {
        return $this->actualValue;
    }

    public function getEquivalentValue(): Money
    {
        return $this->equivalentValue;
    }
}
