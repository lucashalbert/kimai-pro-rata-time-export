<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Configuration;

use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\Customer;
use App\Entity\CustomerMeta;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Entity\UserPreference;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against Kimai's real entities (no database), same bootstrap as
 * Tests/Unit/Service/RateResolverTest.php.
 *
 * @see docs/kimai-version-notes.md §7
 */
final class CompensationConfigurationTest extends TestCase
{
    private const GLOBAL_KEY = 'pro_rata_time_export.base_rate';

    private static function configuration(?float $globalBaseRate): CompensationConfiguration
    {
        $loader = new class implements ConfigLoaderInterface {
            public function getConfigurations(): array
            {
                return [];
            }
        };

        $settings = null === $globalBaseRate ? [] : [self::GLOBAL_KEY => $globalBaseRate];

        return new CompensationConfiguration(new SystemConfiguration($loader, $settings));
    }

    private static function record(?Project $project, ?User $user): Timesheet
    {
        $record = new Timesheet();
        $record->setBegin(new \DateTime('2026-09-03 09:00:00', new \DateTimeZone('UTC')));
        $record->setEnd(new \DateTime('2026-09-03 13:00:00', new \DateTimeZone('UTC')));
        $record->setDuration(4 * 3600);
        $record->setHourlyRate(120.0);
        $record->setRate(480.0);

        if (null !== $project) {
            $record->setProject($project);
        }
        if (null !== $user) {
            $record->setUser($user);
        }

        return $record;
    }

    private static function projectWithOverride(mixed $override, ?Customer $customer = null): Project
    {
        $project = (new Project())->setName('Project A');

        if (null !== $customer) {
            $project->setCustomer($customer);
        }

        if (null !== $override) {
            $project->setMetaField(
                (new ProjectMeta())->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)->setValue($override)
            );
        }

        return $project;
    }

    private static function customerWithOverride(mixed $override): Customer
    {
        $customer = new Customer('Customer A');

        if (null !== $override) {
            $customer->setMetaField(
                (new CustomerMeta())->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)->setValue($override)
            );
        }

        return $customer;
    }

    private static function userWithOverride(mixed $override): User
    {
        $user = new User();
        $user->setUserIdentifier('alice');

        if (null !== $override) {
            $user->addPreference(new UserPreference(CompensationConfiguration::OVERRIDE_FIELD_NAME, $override));
        }

        return $user;
    }

    public function testResolvesGlobalValueWhenNoOverridesAreConfigured(): void
    {
        $config = self::configuration(150.0);
        $record = self::record(self::projectWithOverride(null), self::userWithOverride(null));

        self::assertSame(150.0, $config->getBaseRate($record));
        self::assertTrue($config->hasBaseRate($record));
    }

    public function testUserOverrideBeatsGlobalValue(): void
    {
        $config = self::configuration(150.0);
        $record = self::record(self::projectWithOverride(null), self::userWithOverride(135.0));

        self::assertSame(135.0, $config->getBaseRate($record));
    }

    public function testCustomerOverrideBeatsUserOverride(): void
    {
        $config = self::configuration(150.0);
        $customer = self::customerWithOverride(140.0);
        $record = self::record(self::projectWithOverride(null, $customer), self::userWithOverride(135.0));

        self::assertSame(140.0, $config->getBaseRate($record));
    }

    public function testProjectOverrideBeatsCustomerAndUserOverrides(): void
    {
        $config = self::configuration(150.0);
        $customer = self::customerWithOverride(140.0);
        $record = self::record(self::projectWithOverride(180.0, $customer), self::userWithOverride(135.0));

        self::assertSame(180.0, $config->getBaseRate($record));
    }

    public function testThrowsNotConfiguredWhenNothingIsConfiguredAtAnyLevel(): void
    {
        $config = self::configuration(null);
        $record = self::record(self::projectWithOverride(null), self::userWithOverride(null));

        self::assertFalse($config->hasBaseRate($record));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Unable to generate compensation-equivalent report: Employer base rate is not configured.'
        );
        $config->getBaseRate($record);
    }

    /**
     * @return iterable<string, array{0: string, 1: float}>
     */
    public static function invalidOverrideLevels(): iterable
    {
        yield 'project zero' => ['project', 0.0];
        yield 'project negative' => ['project', -180.0];
        yield 'customer zero' => ['customer', 0.0];
        yield 'customer negative' => ['customer', -140.0];
        yield 'user zero' => ['user', 0.0];
        yield 'user negative' => ['user', -135.0];
        yield 'global zero' => ['global', 0.0];
        yield 'global negative' => ['global', -150.0];
    }

    /**
     * Spec §7: an invalid/non-positive override at any level is an error and
     * MUST NOT fall through to a less specific level. Every level other than
     * the one under test is left unconfigured, so a wrongly-implemented
     * fallback would either resolve a *different* value or hit the
     * "not configured" message instead of this level-specific one.
     */
    #[DataProvider('invalidOverrideLevels')]
    public function testInvalidOverrideThrowsWithoutFallingBackToALessSpecificLevel(string $level, float $invalidValue): void
    {
        $customer = self::customerWithOverride('customer' === $level ? $invalidValue : null);
        $project = self::projectWithOverride('project' === $level ? $invalidValue : null, $customer);
        $user = self::userWithOverride('user' === $level ? $invalidValue : null);
        $globalBaseRate = 'global' === $level ? $invalidValue : null;

        $config = self::configuration($globalBaseRate);
        $record = self::record($project, $user);

        self::assertFalse($config->hasBaseRate($record));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf(
            'Unable to generate compensation-equivalent report: The %s employer base rate must be a number greater than zero.',
            $level
        ));
        $config->getBaseRate($record);
    }
}
