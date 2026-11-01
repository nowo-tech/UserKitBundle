<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Audit event: a session was registered (sign-in, or the first request of a session that existed before the feature
 * was enabled). Dispatched after the row is written; the session id is never part of the event.
 */
final readonly class UserSessionStartedEvent
{
    /**
     * @param object $user security user of the session
     * @param bool $atLogin true at sign-in, false when an already signed-in session was registered
     */
    public function __construct(
        public object $user,
        public SessionUser $owner,
        public SessionClient $client,
        public bool $atLogin,
    ) {
    }
}
