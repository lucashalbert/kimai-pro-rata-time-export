<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

/**
 * A compensation-equivalent report cannot be produced because a rate it
 * depends on (effective or employer base) is missing or unusable (spec §32).
 *
 * The message is written for the person running the export; the export
 * renderers show it to them instead of letting it become a generic 500.
 */
class CompensationUnavailableException extends \RuntimeException
{
}
