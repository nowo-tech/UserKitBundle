<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\UnresolvedSessionOwnerException;
use Nowo\UserKitBundle\Sessions\Ui\SessionRow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The session registry API an application service depends on (aliased to {@see UserSessionManager}): type against it
 * to unit-test without the bundle. Every method takes the user as `object`; nothing returns an ORM entity.
 *
 * The revoking methods log a warning (or throw {@see UnresolvedSessionOwnerException} with
 * `sessions.owner.on_unresolved: exception`) when the user has no session owner.
 */
interface UserSessionsInterface
{
    /**
     * Owner reference of a user (null when the user cannot be registered).
     */
    public function owner(object $user): ?SessionUser;

    /**
     * Limits of the user from the configured resolver (`sessions.limit.*`).
     */
    public function limitFor(object $user): SessionLimit;

    /**
     * Registry hash of the current session, to pass as `$currentHash` / `$except`.
     */
    public function currentHash(Request|SessionInterface|null $session): ?string;

    /**
     * The live sessions of a user as read DTOs, the most recently active first.
     *
     * @return list<SessionRow>
     */
    public function listRowsFor(object $user, ?string $currentHash = null): array;

    /**
     * Signs one session of the user out.
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     */
    public function revoke(object $user, string $publicId, ?object $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false): bool;

    /**
     * Signs out every session of the user except `$currentHash`.
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     */
    public function revokeOthers(object $user, ?string $currentHash, ?object $actor = null, ?string $customReason = null, array $context = []): int;

    /**
     * Signs out every session of the user, `$except` left out (password changed, account disabled, "everywhere"…).
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     */
    public function revokeAll(object $user, ?object $actor = null, RevocationReason $reason = RevocationReason::Everywhere, ?string $except = null, ?string $customReason = null, array $context = []): int;

    /**
     * Signs out the sessions of one device of the user, `$except` left out.
     *
     * @param array<string, mixed> $context host-defined data for {@see UserSessionsRevokedEvent}
     */
    public function revokeDevice(object $user, string $deviceId, ?string $except = null, ?object $actor = null, ?string $customReason = null, array $context = [], bool $invalidateRememberMe = false): int;

    /**
     * Deletes every registry row of the user (call it before deleting the user).
     */
    public function forgetUser(object $user): int;
}
