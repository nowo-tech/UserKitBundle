<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Audit event: a session was registered (sign-in, or the first request of a session that existed before the feature
 * was enabled). Dispatched after the row is written; the session id is never part of the event.
 *
 * Since 1.23.0 (DDD-host checklist B4): `owner` is the user's id reference and `occurredAt` when the row was written; the
 * `user` object is deprecated.
 */
final readonly class UserSessionStartedEvent
{
    /** Since 1.23.0: when the session was registered (the bundle's clock; the construction time when not given). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param object $user deprecated since 1.23.0, read `owner`: security user of the session
     * @param bool $atLogin true at sign-in, false when an already signed-in session was registered
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the session was registered (null = now)
     */
    public function __construct(
        /** @deprecated since 1.23.0, read {@see self::$owner}; removed in 2.0 */
        public object $user,
        public SessionUser $owner,
        public SessionClient $client,
        public bool $atLogin,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
