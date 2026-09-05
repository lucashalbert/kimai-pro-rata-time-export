<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;

/**
 * Symfony derives this extension's alias from the class name:
 * ProRataTimeExportExtension -> "pro_rata_time_export".
 *
 * registerBundleConfiguration() (from Kimai's AbstractPluginExtension) merges the
 * processed config into the "kimai.bundles.config" container parameter, which
 * AppExtension then folds into "kimai.config" as flat dot-notation keys readable
 * through App\Configuration\SystemConfiguration::find().
 *
 * @see docs/kimai-version-notes.md §3
 */
final class ProRataTimeExportExtension extends AbstractPluginExtension
{
    /**
     * @param array<mixed> $configs
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->registerBundleConfiguration($container, $config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }
}
