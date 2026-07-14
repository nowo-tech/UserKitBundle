<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Session;

/**
 * Default no-op strategy.
 *
 * Replace this service with a custom implementation for Redis/database session stores
 * or remember-me token cleanup in your application.
 */
final class DefaultSessionInvalidator implements SessionInvalidatorInterface
{
    public function invalidateSessionsForUser(object $user): void
    {
    }
}
