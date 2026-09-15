<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Export;

use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationUnavailableException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a missing/unusable rate (spec §32) into a readable 422 page.
 *
 * Kimai's `ExportController::export()` does not catch renderer exceptions in
 * any supported version, so an uncaught one becomes Kimai's generic 500 page
 * and the specific message is lost. Kimai's export form opens the result in a
 * new tab, so the page itself is where the user sees it.
 *
 * Only `CompensationUnavailableException` is converted: returning a Response
 * lets Kimai continue, so any failure that must abort the request (for
 * example the mark-as-exported guard, which would otherwise let Kimai mark
 * the records exported) keeps throwing.
 */
trait RendersCompensationUnavailable
{
    private static function compensationUnavailableResponse(CompensationUnavailableException $exception): Response
    {
        $message = htmlspecialchars($exception->getMessage(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Export failed</title></head>'
            . '<body style="font-family: sans-serif; margin: 2rem;"><h1>Export failed</h1><p>' . $message . '</p></body></html>',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
}
