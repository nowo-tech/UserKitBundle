<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Security;

use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountStatusUserCheckerEdgeCasesTest extends TestCase
{
    public function testPreAuthIsNoOp(): void
    {
        $checker = new AccountStatusUserChecker('enabled', PropertyAccess::createPropertyAccessor());
        $checker->checkPreAuth(new PlainUser());
        $this->addToAssertionCount(1);
    }

    public function testMissingReadableFieldAllowsLogin(): void
    {
        $checker = new AccountStatusUserChecker('missing', PropertyAccess::createPropertyAccessor());
        $checker->checkPostAuth(new PlainUser());
        $this->addToAssertionCount(1);
    }
}

class PlainUser implements UserInterface
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
        return 'plain';
    }
}
