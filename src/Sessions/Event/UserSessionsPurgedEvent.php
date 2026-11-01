<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use DateTimeImmutable;

/**
 * Audit event: the retention purge deleted the rows not seen since `before`.
 */
final readonly class UserSessionsPurgedEvent
{
    public function __construct(
        public DateTimeImmutable $before,
        public int $deleted,
    ) {
    }
}
