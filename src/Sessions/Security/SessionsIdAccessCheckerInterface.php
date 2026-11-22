<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Nowo\UserKitBundle\Sessions\Access\SessionAccessInterface;
use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Since 1.23.0 (DDD-host checklist B8): the ids-only twin of {@see SessionsAccessCheckerInterface}, which
 * {@see SessionAccessInterface} asks. Implement it on the checker you set as `sessions.security.access_checker` (next to
 * the object methods, with the same rules) so your host can check session access without a security user object.
 * The built-in role and dev checkers implement it. A configured checker without it makes every id check answer no
 * (fail closed).
 *
 * Answer from the ids and roles you are given and from your own data read now; keep nothing between calls (FrankenPHP
 * worker mode, kernel reset off). Compare owner references exactly (`===` on type and id).
 */
interface SessionsIdAccessCheckerInterface
{
    /**
     * Whether `$actor` may see and sign out their own sessions ("My sessions").
     */
    public function canAccessOwnById(SessionActor $actor): bool;

    /**
     * Whether `$actor` may see and sign out the sessions of `$subject` (administration).
     */
    public function canManageById(SessionActor $actor, SessionUser $subject): bool;
}
