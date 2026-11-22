<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Nowo\UserKitBundle\Sessions\Access\SessionAccessInterface;
use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Since 1.23.0: stands for a `sessions.security.access_checker` service that does not exist while the session pages are
 * off. 1.22 did not register the checker without the pages, so such a container compiled; it still does, and
 * {@see SessionAccessInterface} answers no to everything (fail closed). The container build logs it once.
 *
 * @internal
 */
final readonly class UnavailableSessionsAccessChecker implements SessionsAccessCheckerInterface, SessionsIdAccessCheckerInterface
{
    /**
     * @param string $serviceId the configured service id that does not exist
     */
    public function __construct(
        public string $serviceId,
    ) {
    }

    public function canAccessOwn(?object $user): bool
    {
        return false;
    }

    public function canManage(?object $actor, object $subject): bool
    {
        return false;
    }

    public function canAccessOwnById(SessionActor $actor): bool
    {
        return false;
    }

    public function canManageById(SessionActor $actor, SessionUser $subject): bool
    {
        return false;
    }
}
