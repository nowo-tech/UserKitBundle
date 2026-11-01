<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default checker: "My sessions" needs any of `security.access_roles`, the administration page any of
 * `security.manage_roles`. Both lists are never empty (the configuration refuses an empty list, pattern P1: it cannot
 * fail open); anonymous users are denied.
 */
final readonly class RoleSessionsAccessChecker implements SessionsAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles non-empty
     * @param list<string> $manageRoles non-empty
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $manageRoles,
    ) {
    }

    /**
     * Any access role granted.
     */
    public function canAccessOwn(?object $user): bool
    {
        return $user !== null && $this->anyGranted($this->accessRoles);
    }

    /**
     * Any manage role granted.
     */
    public function canManage(?object $actor, object $subject): bool
    {
        return $actor !== null && $this->anyGranted($this->manageRoles);
    }

    /**
     * @param list<string> $roles
     */
    private function anyGranted(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
