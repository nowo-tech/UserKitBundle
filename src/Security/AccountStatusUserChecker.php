<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Security;

use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ProfileRegistry $registry,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $profile = $this->registry->resolveForObject($user);
        if (!$profile instanceof \Nowo\UserKitBundle\Profile\ProfileSettings || !$profile->accountStatusEnabled) {
            return;
        }

        if ($this->isAccountEnabled($user, $profile->enabledField)) {
            return;
        }

        $exception = new DisabledException('User account is disabled.');
        $exception->setUser($user);

        throw $exception;
    }

    private function isAccountEnabled(UserInterface $user, string $enabledField): bool
    {
        if ($user instanceof AccountStatusInterface) {
            return $user->isEnabled();
        }

        if (!$this->propertyAccessor->isReadable($user, $enabledField)) {
            return true;
        }

        $value = $this->propertyAccessor->getValue($user, $enabledField);

        return (bool) $value;
    }
}
