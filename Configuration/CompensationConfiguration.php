<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Configuration;

use App\Configuration\SystemConfiguration;
use App\Entity\ExportableItem;
use App\Entity\UserPreference;
use KimaiPlugin\ProRataTimeExportBundle\Service\CompensationUnavailableException;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Typed access to the plugin's own configuration (spec §7, §26).
 *
 * SystemConfiguration::find() returns string|int|bool|float|null and its typed
 * getters are private, so narrowing happens here — see docs/kimai-version-notes.md §3.
 *
 * The employer base rate is resolved through a hierarchy, most specific wins
 * (spec §7): a project-level override, then a customer-level override, then a
 * user-level override, then the global default below. Project/customer
 * overrides are read from a Kimai meta field (App\Entity\EntityWithMetaFields,
 * present on Project and Customer in both 2.40.0 and 2.65.0 — see
 * docs/kimai-version-notes.md §7). Kimai's User entity does not implement
 * EntityWithMetaFields; the user-level override is instead read from Kimai's
 * separate UserPreference mechanism via User::getPreferenceValue(). The native
 * edit fields are registered by OverrideFieldDefinitionSubscriber.
 */
final class CompensationConfiguration
{
    private const KEY_BASE_RATE = 'pro_rata_time_export.base_rate';

    /**
     * Name shared by the project- and customer-level meta fields and by the
     * user-level preference that carry a base rate override (spec §7).
     */
    public const OVERRIDE_FIELD_NAME = 'pro_rata_base_rate';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    /**
     * The employer base rate at which equivalent time is expressed, resolved
     * for the given record through the spec §7 hierarchy.
     *
     * Every level is validated `> 0` independently: an invalid or
     * non-positive override at any level is an error and does NOT fall
     * through to a less specific level, because that would silently produce
     * a wrong number for whoever configured the override (spec §7, §32).
     *
     * @throws CompensationUnavailableException when no level has a usable value, or when any configured level is not a number greater than zero
     */
    public function getBaseRate(ExportableItem $item): float
    {
        $resolved = null;

        foreach ($this->overrides($item) as $level => $value) {
            if (null === $value) {
                continue;
            }

            $rate = $this->assertPositive($value, $level);
            $resolved ??= $rate;
        }

        if (null !== $resolved) {
            return $resolved;
        }

        throw new CompensationUnavailableException(
            'Unable to generate compensation-equivalent report: Employer base rate is not configured.'
        );
    }

    /**
     * Whether a usable base rate is configured at any level, for UI/preflight
     * checks that need to report the problem rather than throw.
     */
    public function hasBaseRate(ExportableItem $item): bool
    {
        try {
            $this->getBaseRate($item);
        } catch (CompensationUnavailableException) {
            return false;
        }

        return true;
    }

    /**
     * Candidate values, most specific first. A `null` project/customer/user
     * short-circuits its own level to `null` via the nullsafe operator, which
     * is treated the same as "not configured" below.
     *
     * @return iterable<string, mixed>
     */
    private function overrides(ExportableItem $item): iterable
    {
        $project = $item->getProject();

        yield 'project' => $project?->getMetaField(self::OVERRIDE_FIELD_NAME)?->getValue();
        yield 'customer' => $project?->getCustomer()?->getMetaField(self::OVERRIDE_FIELD_NAME)?->getValue();
        yield 'user' => self::rawPreferenceValue($item->getUser()?->getPreference(self::OVERRIDE_FIELD_NAME));
        yield 'global' => $this->configuration->find(self::KEY_BASE_RATE);
    }

    /**
     * `UserPreference::getValue()` casts by its non-persisted type: once
     * OverrideFieldDefinitionSubscriber has typed the preference `NumberType`
     * in this request (as it has during an export), an unset (null) value
     * reads as `0.0` and would be reported as invalid instead of unset. An
     * untyped copy returns the stored value as-is.
     */
    private static function rawPreferenceValue(?UserPreference $preference): mixed
    {
        return null === $preference ? null : (clone $preference)->setType(TextType::class)->getValue();
    }

    private function assertPositive(mixed $value, string $level): float
    {
        $rate = \is_numeric($value) ? (float) $value : \NAN;

        if (!($rate > 0.0) || !\is_finite($rate)) {
            throw new CompensationUnavailableException(\sprintf(
                'Unable to generate compensation-equivalent report: The %s employer base rate must be a number greater than zero.',
                $level
            ));
        }

        return $rate;
    }
}
