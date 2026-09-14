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
 * The audit/reconciliation export (spec §23): the full field set proving how
 * the employer-facing timecard was derived from each source record. Gated on
 * Kimai's rate-viewing permission (spec §18, §31) because every column beyond
 * identity/date is rate-derived.
 */
final class CompensationEquivalentAuditCsvExporter extends AbstractSpreadsheetRenderer implements RendererInterface, TimesheetExportInterface
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
        return 'compensation-equivalent-audit-csv';
    }

    public function getTitle(): string
    {
        return 'Compensation Equivalent Reconciliation (Audit CSV)';
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
            self::auditHeader(),
            \array_map(self::auditRow(...), $records),
            [[' '], ...self::summaryRows($summary)]
        );

        return $this->getFileResponse(
            $file->getPathname(),
            (new ExportFilename($query))->getFilename() . '-audit-reconciliation.csv',
            'text/csv'
        );
    }
}
