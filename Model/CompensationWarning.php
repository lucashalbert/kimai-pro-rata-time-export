<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

use App\Entity\ExportableItem;

/**
 * A disclosed problem with one source record, carried either on the derived
 * CompensationEquivalentRecord it concerns or at batch level when no derived
 * record exists, so it survives to the review UI and summary rather than being
 * logged and dropped (spec §32, §33).
 *
 * Immutable; identified by reason + affected source record id + a
 * human-readable message, mirroring UnusableEffectiveRateException's
 * "Timesheet #<id>" labelling.
 */
final class CompensationWarning
{
    private function __construct(
        public readonly CompensationWarningReason $reason,
        public readonly ?int $sourceTimesheetId,
        public readonly string $message,
    ) {
    }

    public static function runningRecordExcluded(ExportableItem $item): self
    {
        return new self(
            CompensationWarningReason::RUNNING_RECORD_EXCLUDED,
            $item->getId(),
            \sprintf('%s is currently running and was excluded.', self::label($item))
        );
    }

    public static function overlappingSourceRecords(ExportableItem $item, ExportableItem $overlapsWith): self
    {
        return new self(
            CompensationWarningReason::OVERLAPPING_SOURCE_RECORDS,
            $item->getId(),
            \sprintf(
                '%s overlaps %s. Both were transformed independently; no source records were modified.',
                self::label($item),
                self::label($overlapsWith)
            )
        );
    }

    public static function zeroDurationRecord(ExportableItem $item): self
    {
        return new self(
            CompensationWarningReason::ZERO_DURATION_RECORD,
            $item->getId(),
            \sprintf('%s has a recorded duration of zero.', self::label($item))
        );
    }

    public static function durationTimestampMismatch(ExportableItem $item, int $storedSeconds, int $wallClockSeconds): self
    {
        return new self(
            CompensationWarningReason::DURATION_TIMESTAMP_MISMATCH,
            $item->getId(),
            \sprintf(
                '%s recorded duration (%d seconds) disagrees with its begin/end/break difference (%d seconds).',
                self::label($item),
                $storedSeconds,
                $wallClockSeconds
            )
        );
    }

    /**
     * Value equality, used to de-duplicate the same warning when record-level
     * and batch-level warnings are collected into one summary.
     */
    public function equals(self $other): bool
    {
        return $this->reason === $other->reason
            && $this->sourceTimesheetId === $other->sourceTimesheetId
            && $this->message === $other->message;
    }

    private static function label(ExportableItem $item): string
    {
        return 'Timesheet #' . ($item->getId() ?? '(unsaved)');
    }
}
