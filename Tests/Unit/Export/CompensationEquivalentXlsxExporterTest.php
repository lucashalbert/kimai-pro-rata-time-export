<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Export;

use App\Entity\Timesheet;
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentXlsxExporter;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §51 (worksheets: Employer Timecard, Reconciliation, Summary)
 */
final class CompensationEquivalentXlsxExporterTest extends TestCase
{
    use ExportTestFixtures;

    public function testDeniesAccessWithoutTheRateViewingPermission(): void
    {
        $exporter = new CompensationEquivalentXlsxExporter(
            self::calculator(),
            new ReconciliationService(),
            new FakeSecurity(self::user('viewer'), grantedPermissions: [])
        );
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(AccessDeniedException::class);

        $exporter->render([$item], self::query());
    }

    public function testProducesThreeNamedWorksheetsWithSummaryFigures(): void
    {
        $exporter = self::grantedExporter();
        $projectA = self::project('Project A');
        $projectB = self::project('Project B');
        $itemA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 12:00:00', 180 * 60, 150.0, id: 1, project: $projectA);
        $itemB = self::timesheet('2026-09-03 13:00:00', '2026-09-03 17:00:00', 240 * 60, 120.0, id: 2, project: $projectB);

        $response = $exporter->render([$itemA, $itemB], self::query());
        self::assertInstanceOf(BinaryFileResponse::class, $response);

        $sheets = self::readWorkbook($response->getFile()->getPathname());

        self::assertSame(['Employer Timecard', 'Reconciliation', 'Summary'], \array_keys($sheets));

        // note row, blank row, header row, 2 data rows
        self::assertCount(5, $sheets['Employer Timecard']);
        self::assertSame(['Date', 'User', 'Project', 'Start', 'End'], $sheets['Employer Timecard'][2]);
        self::assertSame('16:12', $sheets['Employer Timecard'][4][4]);

        self::assertCount(5, $sheets['Reconciliation']);
        self::assertSame('Source Timesheet ID', $sheets['Reconciliation'][2][0]);
        self::assertSame('0.800000', $sheets['Reconciliation'][4][11]);

        self::assertSame('Summary', $sheets['Summary'][2][0]);
        self::assertContains(['Users', '2'], $sheets['Summary']);
        self::assertContains(['Source Records', '2'], $sheets['Summary']);
        self::assertContains(['Actual Compensation Value', '930.00'], $sheets['Summary']);
        self::assertContains(['Compensation Equivalent Time', '6:12'], $sheets['Summary']);
        self::assertContains(['Rounding Variance', '0.00'], $sheets['Summary']);
    }

    public function testWorkbookPreservesSecondPrecisionActualDurations(): void
    {
        $exporter = self::grantedExporter();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 09:01:30', 90, 60.0, id: 4);

        $response = $exporter->render([$item], self::query());
        $sheets = self::readWorkbook($response->getFile()->getPathname());

        self::assertSame('0:01:30', $sheets['Reconciliation'][3][8]);
        self::assertSame('1.50', $sheets['Reconciliation'][3][15]);
        self::assertContains(['Actual Recorded Time', '0:01:30'], $sheets['Summary']);
    }

    public function testRefusesToRenderWhenKimaiWouldMarkSourceRecordsExported(): void
    {
        $exporter = self::grantedExporter();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot mark source Kimai timesheets as exported');

        $exporter->render([$item], self::markAsExportedQuery());
    }

    public function testWorkbookSurfacesRunningRecordAndOverlapWarnings(): void
    {
        $exporter = self::grantedExporter();
        $user = self::user('alice');
        $completed = self::timesheet('2026-09-03 08:00:00', '2026-09-03 09:00:00', 3600, 150.0, id: 1, user: $user);
        $overlapA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 11:00:00', 7200, 150.0, id: 2, user: $user);
        $overlapB = self::timesheet('2026-09-03 10:00:00', '2026-09-03 12:00:00', 7200, 150.0, id: 3, user: $user);
        $running = self::timesheet('2026-09-03 12:00:00', null, 0, 150.0, id: 4, user: $user);

        $response = $exporter->render([$completed, $overlapA, $overlapB, $running], self::query());
        $sheets = self::readWorkbook($response->getFile()->getPathname());
        $warningText = \implode("\n", \array_map(static fn (array $row): string => \implode(' ', $row), $sheets['Summary']));

        self::assertStringContainsString('Warnings', $warningText);
        self::assertStringContainsString('Timesheet #4 is currently running and was excluded.', $warningText);
        self::assertStringContainsString('Timesheet #2 overlaps Timesheet #3', $warningText);
        self::assertStringContainsString('Timesheet #3 overlaps Timesheet #2', $warningText);
    }

    public function testDoesNotMutateSourceTimesheets(): void
    {
        $exporter = self::grantedExporter();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 20);

        $before = self::snapshot($item);
        $exporter->render([$item], self::query());
        $after = self::snapshot($item);

        self::assertSame($before, $after);
    }

    private static function grantedExporter(): CompensationEquivalentXlsxExporter
    {
        return new CompensationEquivalentXlsxExporter(
            self::calculator(),
            new ReconciliationService(),
            new FakeSecurity(self::user('admin'), grantedPermissions: ['view_rate_other_timesheet', 'view_rate_own_timesheet'])
        );
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private static function readWorkbook(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();

        return $sheets;
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Timesheet $timesheet): array
    {
        return [
            'begin' => $timesheet->getBegin(),
            'end' => $timesheet->getEnd(),
            'duration' => $timesheet->getDuration(),
            'rate' => $timesheet->getRate(),
            'hourlyRate' => $timesheet->getHourlyRate(),
            'fixedRate' => $timesheet->getFixedRate(),
            'user' => $timesheet->getUser(),
            'customer' => $timesheet->getProject()?->getCustomer(),
            'project' => $timesheet->getProject(),
            'activity' => $timesheet->getActivity(),
            'description' => $timesheet->getDescription(),
            'tags' => $timesheet->getTagsAsArray(),
            'billable' => $timesheet->isBillable(),
            'exported' => $timesheet->isExported(),
        ];
    }
}
