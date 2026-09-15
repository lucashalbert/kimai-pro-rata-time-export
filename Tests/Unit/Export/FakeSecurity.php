<?php

/*
 * This file is part of the "Pro Rata Time Export" plugin for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace KimaiPlugin\ProRataTimeExportBundle\Tests\Unit\Export;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A test double for `Symfony\Bundle\SecurityBundle\Security`, standing in for
 * the real container-backed service so ChecksRatePermission can be exercised
 * without booting the Symfony kernel. Overrides only the two methods that
 * trait calls (getUser(), isGranted()); every other Security method is
 * unreachable from plugin code under test.
 */
final class FakeSecurity extends Security
{
    /**
     * @param string[] $grantedPermissions
     */
    public function __construct(
        private readonly ?UserInterface $user,
        private readonly array $grantedPermissions = [],
    ) {
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function isGranted(mixed $attributes, mixed $subject = null): bool
    {
        return \in_array($attributes, $this->grantedPermissions, true);
    }
}
