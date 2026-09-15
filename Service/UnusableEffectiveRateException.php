<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Service;

use App\Entity\ExportableItem;

/**
 * A source record carries no effective hourly rate the plugin may use.
 *
 * Spec §6/§32: the export fails with this message rather than assuming a rate
 * or falling back to the current project rate.
 */
final class UnusableEffectiveRateException extends CompensationUnavailableException
{
    public static function missing(ExportableItem $item): self
    {
        return new self(\sprintf('%s has no effective hourly rate.', self::label($item)));
    }

    public static function invalid(ExportableItem $item): self
    {
        return new self(\sprintf('%s has an invalid effective rate.', self::label($item)));
    }

    public static function fixedRate(ExportableItem $item): self
    {
        return new self(\sprintf('%s is a fixed-rate record and has no effective hourly rate.', self::label($item)));
    }

    private static function label(ExportableItem $item): string
    {
        return 'Timesheet #' . ($item->getId() ?? '(unsaved)');
    }
}
