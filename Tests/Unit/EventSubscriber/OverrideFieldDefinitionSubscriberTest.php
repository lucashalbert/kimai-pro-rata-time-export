<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\EventSubscriber;

use App\Entity\Customer;
use App\Entity\CustomerMeta;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Event\CustomerMetaDefinitionEvent;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\UserPreferenceEvent;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use KimaiPlugin\ProRataTimeExportBundle\EventSubscriber\OverrideFieldDefinitionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Validator\Constraints\Positive;

final class OverrideFieldDefinitionSubscriberTest extends TestCase
{
    private OverrideFieldDefinitionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new OverrideFieldDefinitionSubscriber();
    }

    public function testDefinesProjectOverrideField(): void
    {
        $project = (new Project())->setName('Project A');

        $this->subscriber->defineProjectOverride(new ProjectMetaDefinitionEvent($project));

        $field = $project->getMetaField(CompensationConfiguration::OVERRIDE_FIELD_NAME);
        self::assertInstanceOf(ProjectMeta::class, $field);
        self::assertSame(NumberType::class, $field->getType());
        self::assertSame(['required' => false], $field->getOptions());
        self::assertHasPositiveConstraint($field->getConstraints());
    }

    public function testProjectOverrideFieldIsNotDuplicatedAndKeepsExistingValue(): void
    {
        $project = (new Project())->setName('Project A');
        $existing = (new ProjectMeta())
            ->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)
            ->setValue('155.25');
        $project->setMetaField($existing);

        $this->subscriber->defineProjectOverride(new ProjectMetaDefinitionEvent($project));

        $field = $project->getMetaField(CompensationConfiguration::OVERRIDE_FIELD_NAME);
        self::assertSame($existing, $field);
        self::assertSame(155.25, $field->getValue());
        self::assertSame(NumberType::class, $field->getType());
        self::assertSame(['required' => false], $field->getOptions());
        self::assertHasPositiveConstraint($field->getConstraints());
        self::assertCount(1, $project->getMetaFields());
    }

    public function testDefinesCustomerOverrideField(): void
    {
        $customer = new Customer('Customer A');

        $this->subscriber->defineCustomerOverride(new CustomerMetaDefinitionEvent($customer));

        $field = $customer->getMetaField(CompensationConfiguration::OVERRIDE_FIELD_NAME);
        self::assertInstanceOf(CustomerMeta::class, $field);
        self::assertSame(NumberType::class, $field->getType());
        self::assertSame(['required' => false], $field->getOptions());
        self::assertHasPositiveConstraint($field->getConstraints());
    }

    public function testCustomerOverrideFieldIsNotDuplicatedAndKeepsExistingValue(): void
    {
        $customer = new Customer('Customer A');
        $existing = (new CustomerMeta())
            ->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)
            ->setValue('145.75');
        $customer->setMetaField($existing);

        $this->subscriber->defineCustomerOverride(new CustomerMetaDefinitionEvent($customer));

        $field = $customer->getMetaField(CompensationConfiguration::OVERRIDE_FIELD_NAME);
        self::assertSame($existing, $field);
        self::assertSame(145.75, $field->getValue());
        self::assertSame(NumberType::class, $field->getType());
        self::assertSame(['required' => false], $field->getOptions());
        self::assertHasPositiveConstraint($field->getConstraints());
        self::assertCount(1, $customer->getMetaFields());
    }

    public function testDefinesUserOverridePreference(): void
    {
        $event = new UserPreferenceEvent(new User(), []);

        $this->subscriber->defineUserOverride($event);

        $preference = self::findPreference($event);
        self::assertInstanceOf(UserPreference::class, $preference);
        self::assertSame(NumberType::class, $preference->getType());
        self::assertSame(['label' => 'Employer Base Rate', 'required' => false], $preference->getOptions());
        self::assertHasPositiveConstraint($preference->getConstraints());
    }

    public function testUserOverridePreferenceIsNotDuplicatedOrOverwritten(): void
    {
        $existing = (new UserPreference(CompensationConfiguration::OVERRIDE_FIELD_NAME, 155.0))
            ->setOptions(['label' => 'Existing Label']);
        $event = new UserPreferenceEvent(new User(), [$existing]);

        $this->subscriber->defineUserOverride($event);

        self::assertSame($existing, self::findPreference($event));
        self::assertSame(['label' => 'Existing Label'], $existing->getOptions());
        self::assertCount(1, $event->getPreferences());
    }

    /**
     * @param array<object> $constraints
     */
    private static function assertHasPositiveConstraint(array $constraints): void
    {
        self::assertNotEmpty(array_filter(
            $constraints,
            static fn (object $constraint): bool => $constraint instanceof Positive
        ));
    }

    private static function findPreference(UserPreferenceEvent $event): ?UserPreference
    {
        foreach ($event->getPreferences() as $preference) {
            if ($preference->matches(CompensationConfiguration::OVERRIDE_FIELD_NAME)) {
                return $preference;
            }
        }

        return null;
    }
}
