<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Export;

use App\Export\RendererInterface;
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentAuditCsvExporter;
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentEmployerCsvExporter;
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentReviewRenderer;
use KimaiPlugin\ProRataTimeExportBundle\Export\CompensationEquivalentXlsxExporter;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationCalculator;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Every export entry point shows the specific missing/unusable rate message
 * (spec §32) as a 422 page instead of letting it become Kimai's generic 500.
 */
final class CompensationUnavailableResponseTest extends TestCase
{
    use ExportTestFixtures;

    /**
     * @return iterable<string, array{\Closure(CompensationCalculator): RendererInterface}>
     */
    public static function renderers(): iterable
    {
        $security = new FakeSecurity(self::user('admin'), grantedPermissions: ['view_rate_other_timesheet', 'view_rate_own_timesheet']);

        yield 'review' => [static fn (CompensationCalculator $c) => new CompensationEquivalentReviewRenderer($c, new ReconciliationService(), new Environment(new ArrayLoader()), $security)];
        yield 'employer csv' => [static fn (CompensationCalculator $c) => new CompensationEquivalentEmployerCsvExporter($c, new ReconciliationService(), $security)];
        yield 'audit csv' => [static fn (CompensationCalculator $c) => new CompensationEquivalentAuditCsvExporter($c, new ReconciliationService(), $security)];
        yield 'xlsx' => [static fn (CompensationCalculator $c) => new CompensationEquivalentXlsxExporter($c, new ReconciliationService(), $security)];
    }

    /**
     * @dataProvider renderers
     */
    public function testMissingEmployerBaseRateIsShownToTheUser(\Closure $renderer): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, 150.0, id: 1);

        $response = $renderer(self::calculator(null))->render([$item], self::query());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'Unable to generate compensation-equivalent report: Employer base rate is not configured.',
            (string) $response->getContent()
        );
    }

    /**
     * @dataProvider renderers
     */
    public function testMissingEffectiveRateIsShownToTheUser(\Closure $renderer): void
    {
        $item = self::timesheet('2026-09-03 09:00:00', '2026-09-03 10:00:00', 3600, null, id: 42);

        $response = $renderer(self::calculator())->render([$item], self::query());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Timesheet #42 has no effective hourly rate.', (string) $response->getContent());
    }
}
