<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * State of one session of a user, returned by {@see UserSessionManager::sessionState()}
 * for a session that is not the request's own (AuthKit's parked accounts). Never carries the session id or its hash.
 */
final readonly class SessionState
{
    /**
     * @param string|null $reason stored revocation reason (built-in value or host-defined), only when revoked
     * @param DateTimeImmutable|null $lastSeenAt last registry touch (null when unknown)
     */
    public function __construct(
        public SessionStatus $status,
        public ?string $reason = null,
        public ?DateTimeImmutable $lastSeenAt = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self(SessionStatus::Unknown);
    }

    /**
     * Whether the session would still be accepted on its next request.
     */
    public function isLive(): bool
    {
        return $this->status === SessionStatus::Live;
    }
}
