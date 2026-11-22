<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;
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
 *
 * Since 1.23.0 (DDD-host checklist B4) the event carries ids: `owner` (the user's owner reference), `actorRef` (the
 * actor's, null for automatic revocations and for an actor the owner resolver cannot map) and `occurredAt` (when the
 * rows were revoked, from the bundle's clock). The `user` and `actor` objects are deprecated: read the references.
 */
final readonly class UserSessionsRevokedEvent
{
    /** Since 1.23.0: when the sessions were revoked (the bundle's clock; the construction time when not given). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param object|null $user deprecated since 1.23.0, read `owner`: security user owning the sessions, when known
     * @param object|null $actor deprecated since 1.23.0, read `actorRef`: the user who did it
     * @param list<string> $publicIds public ids of the revoked rows
     * @param array<string, mixed> $context host-defined data of the revoke call
     * @param SessionUser|null $actorRef Since 1.23.0: owner reference of the actor (null: automatic, or not mappable)
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the sessions were revoked (null = now)
     */
    public function __construct(
        public SessionUser $owner,
        /** @deprecated since 1.23.0, read {@see self::$owner}; removed in 2.0 */
        public ?object $user,
        /** @deprecated since 1.23.0, read {@see self::$actorRef}; removed in 2.0 */
        public ?object $actor,
        public RevocationReason $reason,
        public array $publicIds,
        public ?string $customReason = null,
        public array $context = [],
        public bool $rememberMeInvalidated = false,
        public ?SessionUser $actorRef = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * Since 1.23.0: whether another user than the owner did it (exact match of the references; false without an actor).
     */
    public function byOther(): bool
    {
        return $this->actorRef instanceof SessionUser && ($this->actorRef->type !== $this->owner->type || $this->actorRef->id !== $this->owner->id);
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
