<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A second-factor-pending token (no roles) wrapping the final one, like AuthKit's MFA token. It does not extend
 * AbstractToken: the firewall's ContextListener would otherwise treat the missing roles as a changed user.
 */
final class PendingToken implements PendingAuthenticationTokenInterface
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(private TokenInterface $inner)
    {
    }

    public function inner(): TokenInterface
    {
        return $this->inner;
    }

    public function __toString(): string
    {
        return 'PendingToken(' . $this->getUserIdentifier() . ')';
    }

    public function getUserIdentifier(): string
    {
        return $this->inner->getUserIdentifier();
    }

    public function getRoleNames(): array
    {
        return [];
    }

    public function getUser(): ?UserInterface
    {
        return $this->inner->getUser();
    }

    public function setUser(UserInterface $user): void
    {
        $this->inner->setUser($user);
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @return array{0: TokenInterface, 1: array<string, mixed>}
     */
    public function __serialize(): array
    {
        return [$this->inner, $this->attributes];
    }

    /**
     * @param array{0: TokenInterface, 1: array<string, mixed>} $data
     */
    public function __unserialize(array $data): void
    {
        [$this->inner, $this->attributes] = $data;
    }
}
