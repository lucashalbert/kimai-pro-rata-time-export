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
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/**
 * The employer-facing timecard (spec §22): the minimal Date/User/Project/
 * Start/End field set, using the compensation-equivalent start and end times,
 * never the actual ones. Gated behind the rate-viewing permission (spec §18, §31).
 *
 * Registered for both the Export screen and the Timesheet list export
 * dropdown (spec §4).
 */
final class CompensationEquivalentEmployerCsvExporter extends AbstractSpreadsheetRenderer implements RendererInterface, TimesheetExportInterface
{
    use CompensationRowFormatter;
    use WritesCsvFile;
    use ChecksRatePermission;
    use ChecksMarkAsExported;

    public function __construct(
        private readonly CompensationCalculator $calculator,
        private readonly ReconciliationService $reconciliationService,
        private readonly Security $security,
    ) {
    }

    public function getId(): string
    {
        return 'compensation-equivalent-employer-csv';
    }

    public function getTitle(): string
    {
        return 'Compensation Equivalent Timecard (CSV)';
    }

    public function getType(): string
    {
        return 'csv';
    }

    /**
     * @param ExportableItem[] $exportItems
     */
    public function render(array $exportItems, TimesheetQuery $query): Response
    {
        $this->assertDoesNotMarkSourceTimesheets($query);
        $this->assertRateVisible($this->security, $query);

        $result = $this->calculator->calculateAll($exportItems);
        $records = $result->getRecords();
        $summary = $this->reconciliationService->summarize($result, $query->getBegin(), $query->getEnd());

        $file = $this->writeCsvFile(
            self::employerHeader(),
            \array_map(self::employerRow(...), $records),
            [[' '], ...self::summaryRows($summary)]
        );

        return $this->getFileResponse(
            $file->getPathname(),
            (new ExportFilename($query))->getFilename() . '-employer-timecard.csv',
            'text/csv'
        );
    }
}
