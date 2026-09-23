<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\EventSubscriber;

use App\Configuration\SystemConfiguration;
use App\Entity\CustomerMeta;
use App\Entity\ProjectMeta;
use App\Entity\UserPreference;
use App\Event\CustomerMetaDefinitionEvent;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\UserPreferenceEvent;
use KimaiPlugin\ProRataTimeExportBundle\Configuration\CompensationConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Uses MoneyType with the same currency source as Kimai's own rate fields:
 * the customer's currency for project/customer, the default user currency for users.
 */
final class OverrideFieldDefinitionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SystemConfiguration $systemConfiguration)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProjectMetaDefinitionEvent::class => 'defineProjectOverride',
            CustomerMetaDefinitionEvent::class => 'defineCustomerOverride',
            UserPreferenceEvent::class => 'defineUserOverride',
        ];
    }

    public function defineProjectOverride(ProjectMetaDefinitionEvent $event): void
    {
        $currency = $event->getEntity()->getCustomer()?->getCurrency()
            ?? $this->systemConfiguration->getCustomerDefaultCurrency();
        $event->getEntity()->setMetaField(
            (new ProjectMeta())
                ->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)
                ->setLabel('Employer Base Rate')
                ->setType(MoneyType::class)
                ->addConstraint(new Positive())
                ->setOptions(['required' => false, 'currency' => $currency])
        );
    }

    public function defineCustomerOverride(CustomerMetaDefinitionEvent $event): void
    {
        $currency = $event->getEntity()->getCurrency();
        $event->getEntity()->setMetaField(
            (new CustomerMeta())
                ->setName(CompensationConfiguration::OVERRIDE_FIELD_NAME)
                ->setLabel('Employer Base Rate')
                ->setType(MoneyType::class)
                ->addConstraint(new Positive())
                ->setOptions(['required' => false, 'currency' => $currency])
        );
    }

    public function defineUserOverride(UserPreferenceEvent $event): void
    {
        foreach ($event->getPreferences() as $preference) {
            if ($preference->matches(CompensationConfiguration::OVERRIDE_FIELD_NAME)) {
                return;
            }
        }

        $event->addPreference(
            (new UserPreference(CompensationConfiguration::OVERRIDE_FIELD_NAME, null))
                ->setType(MoneyType::class)
                ->addConstraint(new Positive())
                ->setOptions([
                    'label' => 'Employer Base Rate',
                    'required' => false,
                    'currency' => $this->systemConfiguration->getUserDefaultCurrency(),
                ])
        );
    }
}
