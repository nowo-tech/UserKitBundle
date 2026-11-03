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

    /**
     * Nothing known: an empty id or a user that cannot be registered, or (cache revocation store) no row and no marker.
     * With the database store a missing row is {@see self::Revoked} (1.11.1).
     */
    case Unknown = 'unknown';
}
