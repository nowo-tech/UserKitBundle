<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * Why a registered session was ended. Stored in `revoked_reason`, carried by
 * {@see UserSessionsRevokedEvent} and used for the flash message the signed-out
 * browser gets (`sessions.signed_out.<value>` in the `NowoUserKitBundle` domain).
 */
enum RevocationReason: string
{
    /** The owner signed this one session out (My sessions page). */
    case SignedOut = 'signed_out';

    /** The owner signed out every other session. */
    case Others = 'others';

    /** Every session of the account was signed out (owner or API call). */
    case Everywhere = 'everywhere';

    /** Another user (administrator) signed the session out. */
    case Admin = 'admin';

    /** The concurrent-session limit (`evict_oldest`) signed the oldest session out. */
    case Evicted = 'evicted';

    /** The device of the session lost its trust ({@see UserSessionManager::revokeDevice()}). */
    case Device = 'device';

    /** The password of the account changed. */
    case PasswordChanged = 'password_changed';

    /** The account was disabled. */
    case AccountDisabled = 'account_disabled';

    /** Another change took access away (roles, membership…), signalled by the host. */
    case AccountChanged = 'account_changed';

    /** The session stayed idle longer than `sessions.idle_lifetime`. */
    case Expired = 'expired';

    /**
     * Translation key of the flash message shown to the signed-out browser.
     */
    public function messageKey(): string
    {
        return 'sessions.signed_out.' . $this->value;
    }
}
