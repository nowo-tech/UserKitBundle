<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;

/**
 * Audit event: the retention purge deleted the rows not seen since `before` (and, with
 * `sessions.purge.revoked_retention`, the revoked rows revoked before `revokedBefore`; null = same rule as the others).
 */
final readonly class UserSessionsPurgedEvent
{
    public function __construct(
        public DateTimeImmutable $before,
        public int $deleted,
        public ?DateTimeImmutable $revokedBefore = null,
    ) {
    }
}
