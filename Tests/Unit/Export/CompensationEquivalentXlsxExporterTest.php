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
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §51 (two worksheets: Employer Timecard, Reconciliation)
 */
final class CompensationEquivalentXlsxExporterTest extends TestCase
{
    use ExportTestFixtures;

    public function testDeniesAccessWithoutTheRateViewingPermission(): void
    {
        $exporter = new CompensationEquivalentXlsxExporter(
            self::calculator(),
            new FakeSecurity(self::user('viewer'), grantedPermissions: [])
        );
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(AccessDeniedException::class);

        $exporter->render([$item], self::query());
    }

    public function testProducesTwoNamedWorksheetsWithTheExpectedRowCounts(): void
    {
        $exporter = self::grantedExporter();
        $projectA = self::project('Project A');
        $projectB = self::project('Project B');
        $itemA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 12:00:00', 180 * 60, 150.0, id: 1, project: $projectA);
        $itemB = self::timesheet('2026-09-03 13:00:00', '2026-09-03 17:00:00', 240 * 60, 120.0, id: 2, project: $projectB);

        $response = $exporter->render([$itemA, $itemB], self::query());
        self::assertInstanceOf(BinaryFileResponse::class, $response);

        $sheets = self::readWorkbook($response->getFile()->getPathname());

        self::assertSame(['Employer Timecard', 'Reconciliation'], \array_keys($sheets));

        // note row, blank row, header row, 2 data rows
        self::assertCount(5, $sheets['Employer Timecard']);
        self::assertSame(['Date', 'User', 'Project', 'Start', 'End'], $sheets['Employer Timecard'][2]);
        self::assertSame('16:12', $sheets['Employer Timecard'][4][4]);

        self::assertCount(5, $sheets['Reconciliation']);
        self::assertSame('Source Timesheet ID', $sheets['Reconciliation'][2][0]);
        self::assertSame('0.800000', $sheets['Reconciliation'][4][11]);
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
