<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Access;

use Nowo\UserKitBundle\Sessions\Event\SessionIdAccessCheckEvent;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Security\SessionsIdAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\UserSessionsInterface;

/**
 * Since 1.23.0 (DDD-host checklist B8): whether a user may see or sign out one registered session, asked by ids. A host
 * with a private entity manager, or without the security user at hand (a message handler, an API), checks session
 * access without loading a user object and without reading the registry table itself.
 *
 * The actor is an owner reference ({@see SessionUser}: user class and id), or a {@see SessionActor} that also carries
 * the role names to check (a bare reference has none). The rules are the configured checker's
 * ({@see SessionsIdAccessCheckerInterface}): the actor's own session needs "My sessions" access (or manage access to
 * themselves), another user's session needs manage access to its owner. Then the listeners of
 * {@see SessionIdAccessCheckEvent} may refuse, never grant.
 *
 * - view: the session is live (not revoked, seen within `idle_lifetime` + `touch_interval`), as the session pages list it;
 * - revoke: the session is not revoked yet (what {@see UserSessionsInterface::revoke()} acts on).
 *
 * The public id is matched exactly: 32 lowercase hex characters, compared in SQL (byte for byte on MySQL / MariaDB)
 * and again with `===` in PHP, so a look-alike id (other case, padded) never stands for another session. An unknown,
 * malformed or look-alike id, a revoked or expired session and a session the actor may not reach all give the same
 * false: the answer tells nothing about which. A failure of the store, the checker or a listener is logged (its class
 * only) and answered false.
 *
 * Every call reads the registry and asks the checker again; nothing is kept (FrankenPHP worker mode, kernel reset off).
 * The answer holds as of the check only (REQ-RUNTIME-003): the revoke methods do not reuse it; they match the owner of
 * the row again when they write.
 */
interface SessionAccessInterface
{
    /**
     * Whether the actor may see the session (live, and own or managed).
     */
    public function canViewSession(SessionActor|SessionUser $actor, string $publicId): bool;

    /**
     * Whether the actor may sign the session out (not revoked, and own or managed).
     */
    public function canRevokeSession(SessionActor|SessionUser $actor, string $publicId): bool;
}
