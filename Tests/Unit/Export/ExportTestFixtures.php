<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Export;

use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\Query\TimesheetQuery;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationCalculator;
use KimaiPlugin\ProRataTimeExportBundle\Service\DurationScaler;
use KimaiPlugin\ProRataTimeExportBundle\Service\IntervalGenerator;
use KimaiPlugin\ProRataTimeExportBundle\Service\RateResolver;

/**
 * Fixture builders shared by the Export/ renderer tests, mirroring
 * Tests/Unit/Service/CompensationCalculatorTest.php's fixtures (real Kimai
 * entities, no database).
 */
trait ExportTestFixtures
{
    private static function calculator(float $globalBaseRate = 150.0): CompensationCalculator
    {
        return new CompensationCalculator(
            new RateResolver(),
            new DurationScaler(),
            new IntervalGenerator(),
            self::configuration($globalBaseRate)
        );
    }

    private static function configuration(float $globalBaseRate): CompensationConfiguration
    {
        $loader = new class implements ConfigLoaderInterface {
            public function getConfigurations(): array
            {
                return [];
            }
        };

        return new CompensationConfiguration(new SystemConfiguration(
            $loader,
            ['pro_rata_time_export.base_rate' => $globalBaseRate]
        ));
    }

    private static function user(string $identifier): User
    {
        $user = new User();
        $user->setUserIdentifier($identifier);

        return $user;
    }

    private static function project(string $name, ?Customer $customer = null): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setCustomer($customer ?? new Customer('Customer'));

        return $project;
    }

    /**
     * @param int $id a distinct id per fixture keeps assertions unambiguous
     */
    private static function timesheet(
        string $begin,
        string $end,
        int $duration,
        ?float $hourlyRate,
        int $id,
        ?Project $project = null,
        ?User $user = null,
        ?Activity $activity = null,
    ): Timesheet {
        $timezone = new \DateTimeZone('UTC');
        $record = new Timesheet();
        $record->setBegin(new \DateTime($begin, $timezone));
        $record->setEnd(new \DateTime($end, $timezone));
        $record->setDuration($duration);
        $record->setHourlyRate($hourlyRate);
        $record->setRate(($hourlyRate ?? 0.0) * ($duration / 3600));
        $record->setProject($project ?? self::project('Project'));
        $record->setUser($user ?? self::user('user' . $id));
        if (null !== $activity) {
            $record->setActivity($activity);
        }
        (new \ReflectionProperty(Timesheet::class, 'id'))->setValue($record, $id);

        return $record;
    }

    private static function query(?User $user = null): TimesheetQuery
    {
        $query = new TimesheetQuery();
        $query->setUser($user);

        return $query;
    }
}
