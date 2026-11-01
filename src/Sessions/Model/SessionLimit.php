<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;

/**
 * Concurrent-session limit of one user, as returned by a
 * {@see SessionLimitResolverInterface} (null = unlimited).
 */
final readonly class SessionLimit
{
    /**
     * @param int|null $maxSessions live sessions the user may hold at once (null = unlimited, else >= 1)
     */
    public function __construct(
        public ?int $maxSessions = null,
        public SessionLimitPolicy $policy = SessionLimitPolicy::EvictOldest,
    ) {
        if ($maxSessions !== null && $maxSessions < 1) {
            throw new InvalidArgumentException('A session limit must be at least 1 (null = unlimited).');
        }
    }

    /**
     * No limit.
     */
    public static function unlimited(): self
    {
        return new self();
    }

    /**
     * Whether a limit applies.
     */
    public function isLimited(): bool
    {
        return $this->maxSessions !== null;
    }
}
