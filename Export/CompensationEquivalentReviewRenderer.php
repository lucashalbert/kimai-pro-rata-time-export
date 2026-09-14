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
use App\Export\RendererInterface;
use App\Repository\Query\TimesheetQuery;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationCalculator;
use KimaiPlugin\ProRataTimeExportBundle\Service\ReconciliationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The review/summary screen (spec §19, §20): an HTML response shown on
 * Kimai's Export screen before a user downloads a compensation-equivalent
 * file, so actual and equivalent values are visible together before
 * committing to a download.
 *
 * Gated on the rate-viewing permission (spec §18, §31): every row shows the
 * effective/base rate and both actual and equivalent monetary values.
 *
 * Returns a self-contained HTML document (own `<style>`, no Kimai layout
 * dependency) rather than extending Kimai's app-level `export/layout.html.twig`,
 * which pulls in front-end macros/asset loaders meant for Kimai's own
 * configurable export templates, not a plugin-owned static view.
 */
final class CompensationEquivalentReviewRenderer implements RendererInterface
{
    use ChecksRatePermission;
    use ChecksMarkAsExported;

    public function __construct(
        private readonly CompensationCalculator $calculator,
        private readonly ReconciliationService $reconciliationService,
        private readonly Environment $twig,
        private readonly Security $security,
    ) {
    }

    public function getId(): string
    {
        return 'compensation-equivalent-review';
    }

    public function getTitle(): string
    {
        return 'Compensation Equivalent (Review)';
    }

    /**
     * @param ExportableItem[] $exportItems
     */
    public function render(array $exportItems, TimesheetQuery $query): Response
    {
        $this->assertDoesNotMarkSourceTimesheets($query);
        $this->assertRateVisible($this->security, $query);

        $result = $this->calculator->calculateAll($exportItems);
        // The query's own selected date range is authoritative for the
        // reporting period when present; only an unfiltered query falls back
        // to the records' own extents (see ReconciliationService::summarize()).
        $summary = $this->reconciliationService->summarize($result, $query->getBegin(), $query->getEnd());

        $content = $this->twig->render('@ProRataTimeExport/review.html.twig', [
            'records' => $result->getRecords(),
            'summary' => $summary,
        ]);

        return new Response($content);
    }
}
