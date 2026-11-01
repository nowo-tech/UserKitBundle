<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

use function count;

/**
 * Audit event: sessions of a user were revoked (they are signed out on their next request). Dispatched after the rows
 * are marked, also when nothing matched (`count` 0) for the explicit user actions, so the attempt is auditable.
 * `actor` is the user who did it (null for automatic revocations: limit, password change, account disabled…).
 */
final readonly class UserSessionsRevokedEvent
{
    /**
     * @param object|null $user security user owning the sessions, when known
     * @param list<string> $publicIds public ids of the revoked rows
     */
    public function __construct(
        public SessionUser $owner,
        public ?object $user,
        public ?object $actor,
        public RevocationReason $reason,
        public array $publicIds,
    ) {
    }

    /**
     * Number of revoked sessions.
     */
    public function count(): int
    {
        return count($this->publicIds);
    }
}
