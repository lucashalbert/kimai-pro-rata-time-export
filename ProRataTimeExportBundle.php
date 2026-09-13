<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Kimai discovers this bundle by scanning <kimai>/var/plugins for directories
 * ending in "Bundle" and instantiating KimaiPlugin\<Dir>\<Dir>. The class name,
 * the namespace segment and the installation directory name must therefore all
 * be "ProRataTimeExportBundle", and composer.json must sit next to this
 * file — App\Plugin\Plugin::getMetadata() reads it from Bundle::getPath().
 *
 * @see docs/kimai-version-notes.md §3
 */
final class ProRataTimeExportBundle extends Bundle implements PluginInterface
{
}
