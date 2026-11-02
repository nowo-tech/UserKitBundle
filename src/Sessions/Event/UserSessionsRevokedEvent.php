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
 *
 * `reason` is the built-in category; `customReason` is the host-defined reason passed to the revoke call (stored
 * instead of the category, e.g. `link`), and `context` the free-form data the host passed along (never stored, never
 * logged by the bundle: keep secrets out of it). `rememberMeInvalidated` tells whether the remember-me epoch of the
 * user was bumped (`sessions.remember_me_epoch.enabled`, revokeAll / revokeOthers).
 */
final readonly class UserSessionsRevokedEvent
{
    /**
     * @param object|null $user security user owning the sessions, when known
     * @param list<string> $publicIds public ids of the revoked rows
     * @param array<string, mixed> $context host-defined data of the revoke call
     */
    public function __construct(
        public SessionUser $owner,
        public ?object $user,
        public ?object $actor,
        public RevocationReason $reason,
        public array $publicIds,
        public ?string $customReason = null,
        public array $context = [],
        public bool $rememberMeInvalidated = false,
    ) {
    }

    /**
     * Reason as stored on the rows: the host-defined one, else the built-in value.
     */
    public function reasonCode(): string
    {
        return $this->customReason ?? $this->reason->value;
    }

    /**
     * Number of revoked sessions.
     */
    public function count(): int
    {
        return count($this->publicIds);
    }
}
