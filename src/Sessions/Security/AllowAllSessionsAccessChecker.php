<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * DEV / DEMO only (`security.allow_unauthenticated: true`, refused without kernel.debug): the role checks are skipped.
 * Both pages still need a signed-in user (the controller denies anonymous requests before any lookup).
 */
final class AllowAllSessionsAccessChecker implements SessionsAccessCheckerInterface, SessionsIdAccessCheckerInterface
{
    /**
     * Any signed-in user.
     */
    public function canAccessOwn(?object $user): bool
    {
        return $user !== null;
    }

    /**
     * Always true.
     */
    public function canManage(?object $actor, object $subject): bool
    {
        return true;
    }

    /**
     * Since 1.23.0: always true (an actor reference is a signed-in user).
     */
    public function canAccessOwnById(SessionActor $actor): bool
    {
        return true;
    }

    /**
     * Since 1.23.0: always true.
     */
    public function canManageById(SessionActor $actor, SessionUser $subject): bool
    {
        return true;
    }
}
