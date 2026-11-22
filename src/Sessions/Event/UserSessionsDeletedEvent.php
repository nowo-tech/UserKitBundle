<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * Audit event: every registry row (and the remember-me epoch) of a user was deleted —
 * {@see UserSessionManager::forgetUser()} / `forgetOwner()`, or `sessions.user_removal.delete_sessions` after the user
 * entity was removed. The live sessions were revoked first, so their browsers are signed out on their next request.
 *
 * Since 1.23.0 (DDD-host checklist B4): `owner` is the id reference and `occurredAt` when the rows were deleted; the
 * `user` object is deprecated.
 */
final readonly class UserSessionsDeletedEvent
{
    /** Since 1.23.0: when the rows were deleted (the bundle's clock; the construction time when not given). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param object|null $user deprecated since 1.23.0, read `owner`: the user, when still at hand (null after a Doctrine removal)
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the rows were deleted (null = now)
     */
    public function __construct(
        public SessionUser $owner,
        /** @deprecated since 1.23.0, read {@see self::$owner}; removed in 2.0 */
        public ?object $user,
        public int $deleted,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
