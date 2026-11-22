<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Nowo\UserKitBundle\Sessions\Access\SessionActor;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

use function in_array;

/**
 * Default checker: "My sessions" needs any of `security.access_roles`, the administration page any of
 * `security.manage_roles`. Both lists are never empty (the configuration refuses an empty list, pattern P1: it cannot
 * fail open); anonymous users are denied.
 *
 * Since 1.23.0 the id checks ({@see SessionsIdAccessCheckerInterface}) match the same lists against the role names the
 * {@see SessionActor} carries, expanded through the firewall role hierarchy (`security.role_hierarchy`), exactly
 * (`===`). They never look at the signed-in token: the actor is the one asked about.
 */
final readonly class RoleSessionsAccessChecker implements SessionsAccessCheckerInterface, SessionsIdAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles non-empty
     * @param list<string> $manageRoles non-empty
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $manageRoles,
        private ?RoleHierarchyInterface $roleHierarchy = null,
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
     * Since 1.23.0: any access role among the actor's (hierarchy applied).
     */
    public function canAccessOwnById(SessionActor $actor): bool
    {
        return $this->anyHeld($actor, $this->accessRoles);
    }

    /**
     * Since 1.23.0: any manage role among the actor's (hierarchy applied).
     */
    public function canManageById(SessionActor $actor, SessionUser $subject): bool
    {
        return $this->anyHeld($actor, $this->manageRoles);
    }

    /**
     * Whether one of `$roles` is among the actor's, after the role hierarchy, compared with `===`.
     *
     * @param list<string> $roles
     */
    private function anyHeld(SessionActor $actor, array $roles): bool
    {
        $held = $this->roleHierarchy?->getReachableRoleNames($actor->roles) ?? $actor->roles;
        foreach ($roles as $role) {
            if (in_array($role, $held, true)) {
                return true;
            }
        }

        return false;
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
