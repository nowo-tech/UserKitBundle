<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hexagonal host: a non-entity security user (adapter around the domain user), unknown to Doctrine.
 */
final readonly class SecurityMember implements UserInterface
{
    /**
     * @param non-empty-string $email
     */
    public function __construct(
        private string $email,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
