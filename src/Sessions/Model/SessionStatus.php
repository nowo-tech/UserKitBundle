<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

/**
 * Status of a registered session seen from outside its own requests ({@see SessionState}).
 */
enum SessionStatus: string
{
    /** Registered, not revoked and active within `idle_lifetime` + `touch_interval`. */
    case Live = 'live';

    /** Revoked (its reason is in {@see SessionState::$reason}); signed out on its next request. */
    case Revoked = 'revoked';

    /** Idle longer than `idle_lifetime` + `touch_interval`: signed out on its next request. */
    case Expired = 'expired';

    /** No row for that owner (never registered, signed out, purged or deleted). */
    case Unknown = 'unknown';
}
