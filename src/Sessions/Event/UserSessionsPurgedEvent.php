<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;

/**
 * Audit event: the retention purge deleted the rows not seen since `before` (and, with
 * `sessions.purge.revoked_retention`, the revoked rows revoked before `revokedBefore`; null = same rule as the others).
 * Since 1.23.0: `occurredAt`, when the purge ran.
 */
final readonly class UserSessionsPurgedEvent
{
    /** Since 1.23.0: when the purge ran (the bundle's clock; the construction time when not given). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the purge ran (null = now)
     */
    public function __construct(
        public DateTimeImmutable $before,
        public int $deleted,
        public ?DateTimeImmutable $revokedBefore = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
