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
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentEmployerCsvExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §22 (employer field set/example), §50 (CSV requirements)
 */
final class CompensationEquivalentEmployerCsvExporterTest extends TestCase
{
    use ExportTestFixtures;

    public function testProducesHeaderAndDeterministicColumnsForTheSpecSection46Example(): void
    {
        $exporter = new CompensationEquivalentEmployerCsvExporter(self::calculator());
        $alice = self::user('alice');
        $projectA = self::project('Project A');
        $projectB = self::project('Project B');

        $itemA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 12:00:00', 180 * 60, 150.0, id: 1, project: $projectA, user: $alice);
        $itemB = self::timesheet('2026-09-03 13:00:00', '2026-09-03 17:00:00', 240 * 60, 120.0, id: 2, project: $projectB, user: $alice);

        $response = $exporter->render([$itemA, $itemB], self::query());

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $rows = self::csvRows($response);

        self::assertSame(['Date', 'User', 'Project', 'Start', 'End'], $rows[0]);
        self::assertSame(['2026-09-03', 'alice', 'Project A', '09:00', '12:00'], $rows[1]);
        self::assertSame(['2026-09-03', 'alice', 'Project B', '13:00', '16:12'], $rows[2]);
        self::assertCount(3, $rows);
    }

    public function testMultiUserRecordsPreserveUserIdentityWithoutLeakage(): void
    {
        $exporter = new CompensationEquivalentEmployerCsvExporter(self::calculator());
        $alice = self::user('alice');
        $bob = self::user('bob');

        $aliceItem = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 10, user: $alice);
        $bobItem = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 11, user: $bob);

        $rows = self::csvRows($exporter->render([$aliceItem, $bobItem], self::query()));

        self::assertSame('alice', $rows[1][1]);
        self::assertSame('bob', $rows[2][1]);
        self::assertNotSame($rows[1][1], $rows[2][1]);
    }

    public function testDoesNotMutateSourceTimesheets(): void
    {
        $exporter = new CompensationEquivalentEmployerCsvExporter(self::calculator());
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 20);

        $before = self::snapshot($item);
        $exporter->render([$item], self::query());
        $after = self::snapshot($item);

        self::assertSame($before, $after);
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
