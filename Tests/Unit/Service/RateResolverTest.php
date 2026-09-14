<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Service;

use App\Entity\Project;
use App\Entity\ProjectRate;
use App\Entity\Timesheet;
use KimaiPlugin\ProRataTimeExportBundle\Service\RateResolver;
use KimaiPlugin\ProRataTimeExportBundle\Service\UnusableEffectiveRateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against Kimai's real Timesheet entity (no database), so it needs
 * Kimai's autoloader: mount the plugin at var/plugins/ProRataTimeExportBundle
 * and bootstrap with Kimai's vendor/autoload.php.
 *
 * @see docs/kimai-version-notes.md §2
 */
final class RateResolverTest extends TestCase
{
    private const SOURCE_ID = 18472;

    private RateResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RateResolver();
    }

    /**
     * A saved record as Kimai's RateCalculator leaves it: the rate in force at
     * save time frozen onto the record.
     */
    private static function record(?float $hourlyRate, ?float $fixedRate = null, ?Project $project = null): Timesheet
    {
        $record = new Timesheet();
        $record->setBegin(new \DateTime('2026-09-03 09:00:00', new \DateTimeZone('UTC')));
        $record->setEnd(new \DateTime('2026-09-03 13:00:00', new \DateTimeZone('UTC')));
        $record->setDuration(4 * 3600);
        $record->setHourlyRate($hourlyRate);
        $record->setFixedRate($fixedRate);
        $record->setRate($fixedRate ?? ($hourlyRate ?? 0.0) * 4);
        if (null !== $project) {
            $record->setProject($project);
        }
        (new \ReflectionProperty(Timesheet::class, 'id'))->setValue($record, self::SOURCE_ID);

        return $record;
    }

    public function testResolvesTheHourlyRateStoredOnTheRecord(): void
    {
        $record = self::record(120.0);

        self::assertSame(120.0, $this->resolver->resolveHourlyRate($record));
        self::assertTrue($this->resolver->hasHourlyRate($record));
    }

    /**
     * @return iterable<string, array{?float}>
     */
    public static function missingRates(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => [0.0];
        yield 'negative zero' => [-0.0];
    }

    #[DataProvider('missingRates')]
    public function testMissingRateThrowsTheNamedExceptionWithoutFallingBack(?float $hourlyRate): void
    {
        $project = (new Project())->setName('Project A');
        $currentProjectRate = (new ProjectRate())->setProject($project)->setRate(150.0);
        self::assertSame(150.0, $currentProjectRate->getRate());

        $record = self::record($hourlyRate, project: $project);

        self::assertFalse($this->resolver->hasHourlyRate($record));
        $this->expectException(UnusableEffectiveRateException::class);
        $this->expectExceptionMessage('Timesheet #18472 has no effective hourly rate.');
        $this->resolver->resolveHourlyRate($record);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRates(): iterable
    {
        yield 'negative' => [-120.0];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('invalidRates')]
    public function testInvalidRateThrowsTheNamedException(float $hourlyRate): void
    {
        $record = self::record($hourlyRate);

        self::assertFalse($this->resolver->hasHourlyRate($record));
        $this->expectException(UnusableEffectiveRateException::class);
        $this->expectExceptionMessage('Timesheet #18472 has an invalid effective rate.');
        $this->resolver->resolveHourlyRate($record);
    }

    /**
     * Kimai sets rate = fixedRate on fixed-rate records, but RateCalculator only
     * writes hourlyRate when it is non-null, so an earlier hourly rate can stay
     * on the record. Using it would misprice the record.
     */
    public function testFixedRateRecordFailsClosedAndIgnoresAStaleHourlyRate(): void
    {
        $record = self::record(120.0, fixedRate: 500.0);

        self::assertFalse($this->resolver->hasHourlyRate($record));
        $this->expectException(UnusableEffectiveRateException::class);
        $this->expectExceptionMessage('Timesheet #18472 is a fixed-rate record and has no effective hourly rate.');
        $this->resolver->resolveHourlyRate($record);
    }

    /**
     * Spec §40 critical regression: a project rate change must not reprice
     * records saved before it.
     */
    public function testHistoricalRecordKeepsItsRateAfterTheProjectRateChanges(): void
    {
        $project = (new Project())->setName('Project A');
        $projectRate = (new ProjectRate())->setProject($project)->setRate(120.0);
        $oldRecord = self::record($projectRate->getRate(), project: $project);

        $projectRate->setRate(150.0);
        $newRecord = self::record($projectRate->getRate(), project: $project);

        self::assertSame(150.0, $this->resolver->resolveHourlyRate($newRecord));
        self::assertSame(120.0, $this->resolver->resolveHourlyRate($oldRecord));
    }

    public function testResolverHasNoDependencyThatCouldResolveCurrentRates(): void
    {
        // No constructor means no RateService, TimesheetRepository or
        // *RateRepository can be injected (spec §6, §40).
        self::assertNull((new \ReflectionClass(RateResolver::class))->getConstructor());
    }
}
