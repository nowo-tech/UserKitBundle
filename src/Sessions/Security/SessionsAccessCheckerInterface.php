<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

/**
 * Access to the session pages (REQ-UI-002). Replace the role checker with
 * `nowo_user_kit.sessions.security.access_checker: <service id>` (e.g. "a manager may not act on administrators",
 * organization admins on their own members only).
 */
interface SessionsAccessCheckerInterface
{
    /**
     * Whether the signed-in user may open "My sessions" (list and sign out their own sessions).
     *
     * @param object|null $user security user (null = anonymous)
     */
    public function canAccessOwn(?object $user): bool;

    /**
     * Whether `$actor` may see and sign out the sessions of `$subject` (administration page).
     *
     * @param object|null $actor security user (null = anonymous)
     */
    public function canManage(?object $actor, object $subject): bool;
}
