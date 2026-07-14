<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Security;

use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly string $enabledField,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($this->isAccountEnabled($user)) {
            return;
        }

        $exception = new DisabledException('User account is disabled.');
        $exception->setUser($user);

        throw $exception;
    }

    private function isAccountEnabled(UserInterface $user): bool
    {
        if ($user instanceof AccountStatusInterface) {
            return $user->isEnabled();
        }

        if (!$this->propertyAccessor->isReadable($user, $this->enabledField)) {
            return true;
        }

        $value = $this->propertyAccessor->getValue($user, $this->enabledField);

        return (bool) $value;
    }
}
