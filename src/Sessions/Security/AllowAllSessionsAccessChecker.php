<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

/**
 * DEV / DEMO only (`security.allow_unauthenticated: true`, refused without kernel.debug): the role checks are skipped.
 * Both pages still need a signed-in user (the controller denies anonymous requests before any lookup).
 */
final class AllowAllSessionsAccessChecker implements SessionsAccessCheckerInterface
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
}
