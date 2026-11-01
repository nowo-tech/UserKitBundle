<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;

/**
 * {@see SessionInvalidatorInterface} backed by the session registry: used by
 * `account_status.invalidate_sessions_on_disable` (and any host or AuthKit caller) when `sessions.enabled` is true
 * and the host did not alias its own invalidator. Every session of the user is signed out on its next request.
 */
final readonly class RegistrySessionInvalidator implements SessionInvalidatorInterface
{
    public function __construct(
        private UserSessionManager $sessions,
    ) {
    }

    /**
     * Revokes every registered session of the user (reason `account_disabled`).
     */
    public function invalidateSessionsForUser(object $user): void
    {
        $this->sessions->revokeAll($user, null, RevocationReason::AccountDisabled);
    }
}
