<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

/**
 * Security adapter of the DDD host: a read model of the account, not the Doctrine entity.
 */
final readonly class SecurityAccount implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        public string $accountId,
        private string $email,
        private string $password,
    ) {
    }

    public function getUserIdentifier(): string
    {
        assert($this->email !== '');

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
