<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

/**
 * Writes a UTF-8 CSV file with a deterministic column order and a header row
 * (spec §50), using the same OpenSpout writer Kimai's own
 * `App\Export\Base\CsvRenderer` uses, so field escaping is never hand-rolled.
 */
trait WritesCsvFile
{
    /**
     * @param string[]               $header
     * @param iterable<list<string>> $rows
     * @param list<list<string>>     $footerRows
     */
    private function writeCsvFile(array $header, iterable $rows, array $footerRows = []): \SplFileInfo
    {
        $filename = @tempnam(sys_get_temp_dir(), 'pro-rata-csv');
        if (false === $filename) {
            throw new \RuntimeException('Could not open temporary file.');
        }

        $options = new Options();
        $options->SHOULD_ADD_BOM = false;

        $writer = new Writer($options);
        $writer->openToFile($filename);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        foreach ($footerRows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new \SplFileInfo($filename);
    }
}
