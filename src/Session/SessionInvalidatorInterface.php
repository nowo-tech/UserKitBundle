<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Session;

/**
 * Strategy that ends the active sessions of a user when the account is disabled.
 */
interface SessionInvalidatorInterface
{
    /**
     * Invalidates every active session (and remember-me token) of the user.
     */
    public function invalidateSessionsForUser(object $user): void;
}
