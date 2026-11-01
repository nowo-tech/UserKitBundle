<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

/**
 * DEV / DEMO only (`security.allow_unauthenticated: true`, refused without kernel.debug): the role checks are skipped.
 * "My sessions" still needs a signed-in user (there is nothing to list otherwise).
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
