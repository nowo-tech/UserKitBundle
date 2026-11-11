<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions;

use Nowo\UserKitBundle\Sessions\Model\SessionRef;
use SensitiveParameter;

/**
 * Framework-free port for the registry hash of a session (aliased to {@see UserSessionManager}), the value
 * {@see UserSessionsInterface::listRowsFor()}, `revokeOthers()`, `revokeAll()` and `revokeDevice()` take as
 * `$currentHash` / `$except`. It replaces the deprecated `UserSessionsInterface::currentHash(Request|SessionInterface)`;
 * 2.0 makes {@see UserSessionsInterface} extend it.
 */
interface SessionHashesInterface
{
    /**
     * Registry hash (keyed HMAC) of a session: null for null and for an empty id, the hash of `$session->sessionId()` for a
     * {@see SessionRef}, the hash of the raw session id for a string. A string id of a session that is not registered
     * yields a hash that matches no row, which every registry method treats like null.
     */
    public function sessionHash(#[SensitiveParameter] SessionRef|string|null $session): ?string;
}
