<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Export;

use App\Entity\ExportableItem;
use App\Export\Base\AbstractSpreadsheetRenderer;
use App\Export\ExportFilename;
use App\Export\RendererInterface;
use App\Export\TimesheetExportInterface;
use App\Repository\Query\TimesheetQuery;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationCalculator;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationUnavailableException;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec §51: a single workbook with "Employer Timecard", "Reconciliation" and
 * "Summary" worksheets, built directly on OpenSpout's multi-sheet XLSX writer
 * — the same library `App\Export\Base\XlsxRenderer` uses — rather than a new
 * spreadsheet dependency. Kimai's own `ColumnConverter`/`TemplateInterface` machinery
 * (used by `XlsxRenderer`/`CsvRenderer`) assumes a user-configurable column
 * set resolved from entity getters; this export's columns are fixed
 * derived/computed values (equivalent start/end, factor, rounding
 * difference, ...) that do not exist as getters on `ExportableItem`, so that
 * machinery does not fit and is bypassed in favour of `AbstractSpreadsheetRenderer`'s
 * shared response-building only.
 *
 * Gated on the rate-viewing permission (spec §18, §31).
 */
final class CompensationEquivalentXlsxExporter extends AbstractSpreadsheetRenderer implements RendererInterface, TimesheetExportInterface
{
    use CompensationRowFormatter;
    use ChecksRatePermission;
    use ChecksMarkAsExported;
    use RendersCompensationUnavailable;

    private const NOTE = 'Actual values represent source Kimai records. Equivalent values represent '
        . 'compensation-equivalent time at the configured employer base rate. Source Kimai timesheets '
        . 'are not modified by this report.';

    public function __construct(
        private readonly CompensationCalculator $calculator,
        private readonly ReconciliationService $reconciliationService,
        private readonly Security $security,
    ) {
    }

    public function getId(): string
    {
        return 'compensation-equivalent-xlsx';
    }

    public function getTitle(): string
    {
        return 'Compensation Equivalent Timecard (XLSX)';
    }

    public function getType(): string
    {
        return 'xlsx';
    }

    /**
     * @param ExportableItem[] $exportItems
     */
    public function render(array $exportItems, TimesheetQuery $query): Response
    {
        $this->assertDoesNotMarkSourceTimesheets($query);
        $this->assertRateVisible($this->security, $query);

        try {
            $result = $this->calculator->calculateAll($exportItems);
        } catch (CompensationUnavailableException $exception) {
            return self::compensationUnavailableResponse($exception);
        }
        $records = $result->getRecords();
        $summary = $this->reconciliationService->summarize($result, $query->getBegin(), $query->getEnd());

        $filename = @tempnam(sys_get_temp_dir(), 'pro-rata-xlsx');
        if (false === $filename) {
            throw new \RuntimeException('Could not open temporary file.');
        }

        $writer = new Writer();
        $writer->openToFile($filename);

        $writer->getCurrentSheet()->setName('Employer Timecard');
        self::writeSheet(
            $writer,
            self::employerHeader(),
            \array_map(self::employerRow(...), $records),
            self::warningRows($summary->getWarnings())
        );

        $writer->addNewSheetAndMakeItCurrent()->setName('Reconciliation');
        self::writeSheet(
            $writer,
            self::auditHeader(),
            \array_map(self::auditRow(...), $records),
            self::warningRows($summary->getWarnings())
        );

        $writer->addNewSheetAndMakeItCurrent()->setName('Summary');
        self::writeRows($writer, [
            [self::NOTE],
            [' '],
            ...self::summaryRows($summary),
        ]);

        $writer->close();

        return $this->getFileResponse(
            $filename,
            (new ExportFilename($query))->getFilename() . '-compensation-equivalent.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * @param string[]           $header
     * @param list<list<string>> $rows
     * @param list<list<string>> $footerRows
     */
    private static function writeSheet(Writer $writer, array $header, array $rows, array $footerRows = []): void
    {
        $writer->addRow(Row::fromValues([self::NOTE]));
        // A single space, not an empty string: OpenSpout drops a row containing
        // only empty cells when writing XLSX, which would silently collapse
        // this spacer and shift every row index below it.
        $writer->addRow(Row::fromValues([' ']));
        $writer->addRow(Row::fromValues($header));

        self::writeRows($writer, $rows);
        self::writeRows($writer, $footerRows);
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function writeRows(Writer $writer, array $rows): void
    {
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }
}
