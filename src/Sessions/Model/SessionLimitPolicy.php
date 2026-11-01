<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

/**
 * What a sign-in does when the user already holds `max_sessions` live sessions.
 */
enum SessionLimitPolicy: string
{
    /** The new sign-in succeeds; the least recently active sessions are signed out. */
    case EvictOldest = 'evict_oldest';

    /** The new sign-in is refused (authentication failure); the existing sessions stay. */
    case RejectNew = 'reject_new';
}
