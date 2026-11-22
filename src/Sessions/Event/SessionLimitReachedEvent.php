<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\SessionLimitReachedException;

/**
 * Audit / hook event: a sign-in hit the concurrent-session limit.
 *
 * - `reject_new`: dispatched before the sign-in is refused (the authenticator fails with
 *   {@see SessionLimitReachedException}); a listener may e-mail the user a link
 *   that signs the other sessions out, or set a custom message with {@see self::setMessage()}.
 * - `evict_oldest`: dispatched after the oldest sessions were revoked (`evicted` holds their public ids).
 *
 * Since 1.23.0 (DDD-host checklist B4): `owner` is the user's id reference and `occurredAt` when the limit was hit; the
 * `user` object is deprecated.
 */
final class SessionLimitReachedEvent
{
    private ?string $message = null;

    /** Since 1.23.0: when the limit was hit (the bundle's clock; the construction time when not given). */
    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param object $user deprecated since 1.23.0, read `owner`: the user signing in
     * @param int $activeSessions live sessions of the user before this sign-in
     * @param list<string> $evicted public ids revoked by `evict_oldest`
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the limit was hit (null = now)
     */
    public function __construct(
        /** @deprecated since 1.23.0, read {@see self::$owner}; removed in 2.0 */
        public readonly object $user,
        public readonly SessionUser $owner,
        public readonly SessionLimit $limit,
        public readonly int $activeSessions,
        public readonly array $evicted = [],
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * Whether the sign-in is refused.
     */
    public function isRejected(): bool
    {
        return $this->limit->policy === SessionLimitPolicy::RejectNew;
    }

    /**
     * Replaces the (already translated) authentication error shown on the sign-in form (`reject_new` only).
     */
    public function setMessage(string $message): void
    {
        // @igor-ignore - Event object created per sign-in and dispatched once; not a shared service.
        $this->message = $message;
    }

    /**
     * Custom authentication error, or null for the bundle message.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
