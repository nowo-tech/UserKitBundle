<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Event;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;

/**
 * Audit event: every registry row (and the remember-me epoch) of a user was deleted —
 * {@see UserSessionManager::forgetUser()} / `forgetOwner()`, or `sessions.user_removal.delete_sessions` after the user
 * entity was removed. The live sessions were revoked first, so their browsers are signed out on their next request.
 */
final readonly class UserSessionsDeletedEvent
{
    /**
     * @param object|null $user the user, when still at hand (null after a Doctrine removal)
     */
    public function __construct(
        public SessionUser $owner,
        public ?object $user,
        public int $deleted,
    ) {
    }
}
