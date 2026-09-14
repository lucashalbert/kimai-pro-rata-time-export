<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

final class CompensationCalculationResult
{
    /**
     * @param CompensationEquivalentRecord[] $records
     * @param CompensationWarning[]          $warnings
     */
    public function __construct(
        private readonly array $records,
        private readonly array $warnings = [],
    ) {
    }

    /**
     * @return CompensationEquivalentRecord[]
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @return CompensationWarning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
