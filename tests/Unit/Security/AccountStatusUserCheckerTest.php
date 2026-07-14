<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Security;

use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusUserCheckerTest extends TestCase
{
    public function testDisabledAccountThrows(): void
    {
        $checker = new AccountStatusUserChecker('enabled', PropertyAccess::createPropertyAccessor());

        $this->expectException(DisabledException::class);
        $checker->checkPostAuth(new DisabledUser());
    }

    public function testEnabledAccountPasses(): void
    {
        $checker = new AccountStatusUserChecker('enabled', PropertyAccess::createPropertyAccessor());
        $checker->checkPostAuth(new EnabledUser());
        $this->addToAssertionCount(1);
    }

    public function testCustomFieldName(): void
    {
        $checker = new AccountStatusUserChecker('isActive', PropertyAccess::createPropertyAccessor());
        $this->expectException(DisabledException::class);
        $checker->checkPostAuth(new ActiveFieldUser(false));
    }
}

class EnabledUser implements UserInterface, AccountStatusInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'enabled';
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

class DisabledUser implements UserInterface, AccountStatusInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'disabled';
    }

    public function isEnabled(): bool
    {
        return false;
    }
}

class ActiveFieldUser implements UserInterface
{
    public function __construct(private readonly bool $isActive)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'active-field';
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
