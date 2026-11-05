<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who is erased, by whom, plus free host context (e.g. `['scope' => 'self']`).
 *
 * `originalIdentifier` (1.12) is `UserInterface::getUserIdentifier()` captured by {@see PersonalDataEraser} **before**
 * any eraser ran: an eraser that anonymises the identifier (e-mail) does not hide the person from erasers that run
 * later and look data up by it (AuthKit's account switcher, caches keyed by e-mail). Null for a user that is not a
 * `UserInterface`. Erasers should use {@see self::identifier()}.
 */
final readonly class ErasureRequest
{
    /**
     * Describes one erasure: the user, the optional acting user and free host context.
     *
     * @param array<string, mixed> $context
     * @param string|null $originalIdentifier the user identifier before any eraser ran (1.12)
     */
    public function __construct(
        public object $user,
        public ?object $actor = null,
        public array $context = [],
        public ?string $originalIdentifier = null,
    ) {
    }

    /**
     * The identifier the user had when the erasure started (1.12): `originalIdentifier`, else the current
     * `getUserIdentifier()`, else null.
     */
    public function identifier(): ?string
    {
        if ($this->originalIdentifier !== null) {
            return $this->originalIdentifier;
        }

        return $this->user instanceof UserInterface ? $this->user->getUserIdentifier() : null;
    }

    /** True when the user erases their own data (no actor, or the actor is the user). */
    public function isSelfService(): bool
    {
        return $this->actor === null || $this->actor === $this->user;
    }
}
