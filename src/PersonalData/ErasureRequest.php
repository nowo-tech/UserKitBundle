<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Who is erased, by whom, plus free host context (e.g. `['scope' => 'self']`).
 */
final class ErasureRequest
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly object $user,
        public readonly ?object $actor = null,
        public readonly array $context = [],
    ) {
    }

    /** True when the user erases their own data (no actor, or the actor is the user). */
    public function isSelfService(): bool
    {
        return $this->actor === null || $this->actor === $this->user;
    }
}
