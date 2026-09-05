<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Configuration;

use App\Configuration\SystemConfiguration;

/**
 * Typed access to the plugin's own configuration (spec §7, §26).
 *
 * SystemConfiguration::find() returns string|int|bool|float|null and its typed
 * getters are private, so narrowing happens here — see docs/kimai-version-notes.md §3.
 */
final class CompensationConfiguration
{
    private const KEY_BASE_RATE = 'pro_rata_time_export.base_rate';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    /**
     * The employer base rate at which equivalent time is expressed.
     *
     * Spec §7 requires base_rate > 0, and spec §32 requires an explicit error
     * rather than a silent fallback when it is missing or invalid.
     *
     * @throws \RuntimeException when the base rate is unset or not greater than zero
     */
    public function getBaseRate(): float
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }

    /**
     * Whether a usable base rate is configured, for UI/preflight checks that
     * need to report the problem rather than throw.
     */
    public function hasBaseRate(): bool
    {
        // TODO(follow-up): implemented in a later task
        throw new \LogicException(__METHOD__ . ' is not implemented yet.');
    }
}
