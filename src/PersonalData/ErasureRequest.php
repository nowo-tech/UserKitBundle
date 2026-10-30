<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Who is erased, by whom, plus free host context (e.g. `['scope' => 'self']`).
 */
final readonly class ErasureRequest
{
    /**
     * Describes one erasure: the user, the optional acting user and free host context.
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        public object $user,
        public ?object $actor = null,
        public array $context = [],
    ) {
    }

    /** True when the user erases their own data (no actor, or the actor is the user). */
    public function isSelfService(): bool
    {
        return $this->actor === null || $this->actor === $this->user;
    }
}
