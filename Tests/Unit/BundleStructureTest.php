<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Plugin {
    if (!interface_exists(PluginInterface::class)) {
        interface PluginInterface
        {
        }
    }
}

namespace Symfony\Component\HttpKernel\Bundle {
    if (!class_exists(Bundle::class)) {
        abstract class Bundle
        {
            public function getName(): string
            {
                return (new \ReflectionClass($this))->getShortName();
            }

            public function getPath(): string
            {
                $fileName = (new \ReflectionObject($this))->getFileName();

                return \dirname((string) $fileName);
            }
        }
    }
}

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit {

use App\Plugin\PluginInterface;
use KimaiPlugin\ProRataTimeExportBundle\ProRataTimeExportBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the structural contract Kimai enforces at boot: get any of this wrong
 * and the plugin silently fails to register rather than erroring loudly.
 *
 * @see docs/kimai-version-notes.md §3
 */
final class BundleStructureTest extends TestCase
{
    private const BUNDLE_NAME = 'ProRataTimeExportBundle';
    private const MIN_KIMAI_VERSION_ID = 24000; // Constants::VERSION_ID of Kimai 2.40.0

    /**
     * @return array<mixed>
     */
    private function manifest(): array
    {
        $json = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
        self::assertIsArray($json);

        return $json;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testBundleClassResolvesToKimaiPluginMetadataPath(): void
    {
        $class = 'KimaiPlugin\\' . self::BUNDLE_NAME . '\\' . self::BUNDLE_NAME;
        $psr4 = $this->manifest()['autoload']['psr-4'];
        $autoloadPrefix = 'KimaiPlugin\\' . self::BUNDLE_NAME . '\\';
        $autoloadRoot = rtrim($this->root() . '/' . $psr4[$autoloadPrefix], '/');

        $loader = static function (string $autoloadedClass) use ($autoloadPrefix, $autoloadRoot): void {
            if (!str_starts_with($autoloadedClass, $autoloadPrefix)) {
                return;
            }

            $relativeClass = substr($autoloadedClass, \strlen($autoloadPrefix));
            $path = $autoloadRoot . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        };

        spl_autoload_register($loader);

        try {
            self::assertTrue(class_exists($class));
        } finally {
            spl_autoload_unregister($loader);
        }

        $bundle = new ProRataTimeExportBundle();

        self::assertInstanceOf(PluginInterface::class, $bundle);
        self::assertSame(self::BUNDLE_NAME, $bundle->getName());
        self::assertSame($this->root(), $bundle->getPath());
        self::assertFileExists($bundle->getPath() . '/composer.json');
    }

    public function testManifestDeclaresKimaiPluginMetadata(): void
    {
        $manifest = $this->manifest();

        self::assertSame('kimai-plugin', $manifest['type']);
        // PluginMetadata::createFromArray() throws unless both keys exist and
        // "require" is an integer.
        self::assertIsInt($manifest['extra']['kimai']['require']);
        self::assertSame(self::MIN_KIMAI_VERSION_ID, $manifest['extra']['kimai']['require']);
        self::assertNotEmpty($manifest['extra']['kimai']['name']);
    }

    public function testAutoloadMapsTheKimaiPluginNamespaceToTheRepositoryRoot(): void
    {
        // Kimai's own autoloader maps KimaiPlugin\ to var/plugins/, so
        // KimaiPlugin\<Bundle>\<Bundle> must resolve to <root>/<Bundle>.php.
        $psr4 = $this->manifest()['autoload']['psr-4'];

        self::assertSame('', $psr4['KimaiPlugin\\' . self::BUNDLE_NAME . '\\']);
    }

    public function testBundleClassIsRegisteredAsAService(): void
    {
        // PluginManager collects bundles via #[TaggedIterator(PluginInterface::class)],
        // which only sees classes registered as services. Excluding the bundle
        // class from services.yaml makes the plugin invisible to Kimai's plugin
        // administration while otherwise appearing to work.
        $services = Yaml::parseFile($this->root() . '/Resources/config/services.yaml');
        $serviceId = 'KimaiPlugin\\' . self::BUNDLE_NAME . '\\' . self::BUNDLE_NAME;

        self::assertTrue($services['services']['_defaults']['autoconfigure']);
        self::assertArrayHasKey($serviceId, $services['services']);
    }
}
}
