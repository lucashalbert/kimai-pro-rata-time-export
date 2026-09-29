<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Semantic configuration for the plugin (spec §7, §26).
 *
 * The root node name must match the alias derived from
 * ProRataTimeExportExtension: "pro_rata_time_export".
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pro_rata_time_export');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                // Spec §7 requires configured base rates to be > 0; enforced at read time by
                // CompensationConfiguration so a misconfigured instance fails
                // with the spec §32 message rather than at container compile time.
                ->floatNode('base_rate')
                    ->defaultNull()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
