<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Model;

/**
 * The situations a CompensationWarning can report, each tied to a spec
 * section that requires the export to disclose it rather than hide it.
 */
enum CompensationWarningReason: string
{
    /** Spec §15: a running record was excluded rather than converted. */
    case RUNNING_RECORD_EXCLUDED = 'running_record_excluded';

    /** Spec §13: two source records overlap; both were transformed independently. */
    case OVERLAPPING_SOURCE_RECORDS = 'overlapping_source_records';

    /** Spec §14: a zero-duration record was preserved with a zero-length equivalent interval. */
    case ZERO_DURATION_RECORD = 'zero_duration_record';

    /** Spec §9: the record's stored duration disagrees with its begin/end/break wall-clock difference. */
    case DURATION_TIMESTAMP_MISMATCH = 'duration_timestamp_mismatch';
}
