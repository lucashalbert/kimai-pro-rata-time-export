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
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentAuditCsvExporter;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §23 (audit field set), §47 (rounding worked example),
 *      §18/§31 (rate-viewing permission gate)
 */
final class CompensationEquivalentAuditCsvExporterTest extends TestCase
{
    use ExportTestFixtures;

    public function testDeniesAccessWithoutTheRateViewingPermission(): void
    {
        $exporter = new CompensationEquivalentAuditCsvExporter(
            self::calculator(),
            new ReconciliationService(),
            new FakeSecurity(self::user('viewer'), grantedPermissions: [])
        );
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(AccessDeniedException::class);

        $exporter->render([$item], self::query());
    }

    public function testAllowsAccessWhenNoAuthenticatedUserIsPresent(): void
    {
        $exporter = new CompensationEquivalentAuditCsvExporter(self::calculator(), new ReconciliationService(), new FakeSecurity(null));
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $response = $exporter->render([$item], self::query());

        self::assertInstanceOf(BinaryFileResponse::class, $response);
    }

    public function testProducesTheFullAuditFieldSetWithTheSpecSection47RoundingExample(): void
    {
        $exporter = self::grantedExporter();
        $bob = self::user('bob');
        $projectB = self::project('Project B');
        $item = self::timesheet('2026-09-03 09:17:00', '2026-09-03 09:54:00', 37 * 60, 120.0, id: 3, project: $projectB, user: $bob);

        $rows = self::csvRows($exporter->render([$item], self::query()));

        self::assertSame([
            'Source Timesheet ID', 'User', 'Date', 'Customer', 'Project', 'Activity',
            'Actual Start', 'Actual End', 'Actual Duration',
            'Effective Rate', 'Employer Base Rate', 'Conversion Factor',
            'Equivalent Start', 'Equivalent End', 'Equivalent Duration',
            'Actual Compensation', 'Equivalent Compensation', 'Rounding Difference',
        ], $rows[0]);

        self::assertSame([
            '3', 'bob', '2026-09-03', 'Customer', 'Project B', '',
            '2026-09-03 09:17', '2026-09-03 09:54', '0:37',
            '120.00', '150.00', '0.800000',
            '2026-09-03 09:17', '2026-09-03 09:47', '0:30',
            '74.00', '75.00', '1.00',
        ], $rows[1]);
    }

    public function testAuditCsvPreservesSecondPrecisionActualDurations(): void
    {
        $exporter = self::grantedExporter();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 09:01:30', 90, 60.0, id: 4);

        $rows = self::csvRows($exporter->render([$item], self::query()));

        self::assertSame('0:01:30', $rows[1][8]);
        self::assertSame('1.50', $rows[1][15]);
    }

    public function testRefusesToRenderWhenKimaiWouldMarkSourceRecordsExported(): void
    {
        $exporter = self::grantedExporter();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot mark source Kimai timesheets as exported');

        $exporter->render([$item], self::markAsExportedQuery());
    }

    public function testCsvSurfacesRunningRecordAndOverlapWarnings(): void
    {
        $exporter = self::grantedExporter();
        $user = self::user('alice');
        $completed = self::timesheet('2026-09-03 08:00:00', '2026-09-03 09:00:00', 3600, 150.0, id: 1, user: $user);
        $overlapA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 11:00:00', 7200, 150.0, id: 2, user: $user);
        $overlapB = self::timesheet('2026-09-03 10:00:00', '2026-09-03 12:00:00', 7200, 150.0, id: 3, user: $user);
        $running = self::timesheet('2026-09-03 12:00:00', null, 0, 150.0, id: 4, user: $user);

        $rows = self::csvRows($exporter->render([$completed, $overlapA, $overlapB, $running], self::query()));
        $warningText = \implode("\n", \array_map(static fn (array $row): string => \implode(' ', $row), $rows));

        self::assertStringContainsString('Warnings', $warningText);
        self::assertStringContainsString('Timesheet #4 is currently running and was excluded.', $warningText);
        self::assertStringContainsString('Timesheet #2 overlaps Timesheet #3', $warningText);
        self::assertStringContainsString('Timesheet #3 overlaps Timesheet #2', $warningText);
    }

    public function testMultiUserRecordsPreserveSourceIdAndUserIdentityWithoutLeakage(): void
    {
        $exporter = self::grantedExporter();
        $alice = self::user('alice');
        $bob = self::user('bob');

        $aliceItem = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 10, user: $alice);
        $bobItem = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 11, user: $bob);

        $rows = self::csvRows($exporter->render([$aliceItem, $bobItem], self::query()));

        self::assertSame('10', $rows[1][0]);
        self::assertSame('alice', $rows[1][1]);
        self::assertSame('11', $rows[2][0]);
        self::assertSame('bob', $rows[2][1]);
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

    private static function grantedExporter(): CompensationEquivalentAuditCsvExporter
    {
        return new CompensationEquivalentAuditCsvExporter(
            self::calculator(),
            new ReconciliationService(),
            new FakeSecurity(self::user('admin'), grantedPermissions: ['view_rate_other_timesheet', 'view_rate_own_timesheet'])
        );
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRows(BinaryFileResponse $response): array
    {
        $content = \file_get_contents($response->getFile()->getPathname());
        self::assertNotFalse($content);

        $lines = \preg_split('/\r\n|\n/', \rtrim($content, "\r\n"));
        self::assertNotFalse($lines);

        return \array_map(static fn (string $line): array => \str_getcsv($line), $lines);
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
