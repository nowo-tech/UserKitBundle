<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A second user class whose identifiers overlap the first one's.
 */
final readonly class OwnerImageAdminUser implements UserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private string $id,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
