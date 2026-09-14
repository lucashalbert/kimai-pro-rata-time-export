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
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentReviewRenderer;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @see .specs/kimai-pro-rata-time-export_SPEC.md §19 (per-record table), §20 (summary),
 *      §46/§47 (worked examples), §13 (overlap warnings), §18/§31 (rate-viewing permission gate)
 */
final class CompensationEquivalentReviewRendererTest extends TestCase
{
    use ExportTestFixtures;

    public function testDeniesAccessWithoutTheRateViewingPermission(): void
    {
        $renderer = new CompensationEquivalentReviewRenderer(
            self::calculator(),
            new ReconciliationService(),
            self::twig(),
            new FakeSecurity(self::user('viewer'), grantedPermissions: [])
        );
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(AccessDeniedException::class);

        $renderer->render([$item], self::query());
    }

    public function testRefusesToRenderWhenKimaiWouldMarkSourceRecordsExported(): void
    {
        $renderer = self::grantedRenderer();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot mark source Kimai timesheets as exported');

        $renderer->render([$item], self::markAsExportedQuery());
    }

    public function testRendersPerRecordTableSummaryAndWarningsForTheSpecWorkedExamples(): void
    {
        $renderer = self::grantedRenderer();
        $alice = self::user('alice');

        // spec §46: two records that reconcile exactly.
        $projectA = self::project('Project A');
        $projectB = self::project('Project B');
        $itemA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 12:00:00', 180 * 60, 150.0, id: 1, project: $projectA, user: $alice);
        $itemB = self::timesheet('2026-09-03 13:00:00', '2026-09-03 17:00:00', 240 * 60, 120.0, id: 2, project: $projectB, user: $alice);
        $roundingItem = self::timesheet('2026-09-04 09:17:00', '2026-09-04 09:54:00', 37 * 60, 120.0, id: 5, project: $projectB, user: $alice);

        // an overlapping pair to force a disclosed warning (spec §13).
        $overlapA = self::timesheet('2026-09-03 09:00:00', '2026-09-03 11:00:00', 2 * 3600, 150.0, id: 3, user: $alice);
        $overlapB = self::timesheet('2026-09-03 10:00:00', '2026-09-03 12:00:00', 2 * 3600, 150.0, id: 4, user: $alice);

        $html = $renderer->render([$itemA, $itemB, $roundingItem, $overlapA, $overlapB], self::query())->getContent();

        // per-record detail: actual and equivalent values are both visible.
        self::assertStringContainsString('16:12', $html); // Project B equivalent end
        self::assertStringContainsString('Actual Value', $html);
        self::assertStringContainsString('74.00', $html); // actual value from spec §47 terminology/field
        self::assertStringContainsString('450.00', $html); // Project A actual/equivalent value
        self::assertStringContainsString('480.00', $html); // Project B actual/equivalent value

        self::assertStringContainsString('0.00', $html);

        // warnings are surfaced, not buried (spec §13, §32, §33).
        self::assertStringContainsString('overlaps', $html);
        self::assertStringContainsString('#4', $html);
    }

    public function testReviewPreservesSecondPrecisionActualDurations(): void
    {
        $renderer = self::grantedRenderer();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 09:01:30', 90, 60.0, id: 6);

        $html = $renderer->render([$item], self::query())->getContent();

        self::assertStringContainsString('0:01:30', $html);
        self::assertStringContainsString('0h 01m 30s', $html);
        self::assertStringContainsString('1.50', $html);
    }

    public function testReviewShowsEndDatesForMidnightCrossingRecords(): void
    {
        $renderer = self::grantedRenderer();
        $item = self::timesheet('2026-09-03 23:00:00', '2026-09-04 01:00:00', 2 * 3600, 150.0, id: 7);

        $html = $renderer->render([$item], self::query())->getContent();

        self::assertStringContainsString('23:00', $html);
        self::assertGreaterThanOrEqual(2, \substr_count($html, '2026-09-04 01:00'));
    }

    public function testDoesNotMutateSourceTimesheets(): void
    {
        $renderer = self::grantedRenderer();
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 20);

        $before = self::snapshot($item);
        $renderer->render([$item], self::query());
        $after = self::snapshot($item);

        self::assertSame($before, $after);
    }

    private static function grantedRenderer(): CompensationEquivalentReviewRenderer
    {
        return new CompensationEquivalentReviewRenderer(
            self::calculator(),
            new ReconciliationService(),
            self::twig(),
            new FakeSecurity(self::user('admin'), grantedPermissions: ['view_rate_other_timesheet', 'view_rate_own_timesheet'])
        );
    }

    /**
     * A real Twig environment pointed directly at the plugin's Resources/views
     * directory, mirroring how Symfony's TwigBundle registers a bundle's
     * views under the `@<BundleNameWithoutBundleSuffix>` namespace (see
     * docs/kimai-version-notes.md and vendor/symfony/twig-bundle's
     * TwigExtension::getBundleTemplatePaths()), without booting the kernel.
     */
    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/Resources/views', 'ProRataTimeExport');

        return new Environment($loader, ['strict_variables' => true]);
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
