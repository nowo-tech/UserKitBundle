<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\SessionHashesInterface;
use SensitiveParameter;

/**
 * Framework-free reference to a **registered** HTTP session: its session id, which the registry turns into its keyed
 * hash ({@see SessionHashesInterface::sessionHash()}). Build it at the HTTP edge with
 * `UserSessionManager::sessionRef($request)` (null while the session is not registered) and pass it into application
 * services that must not see `Request` / `SessionInterface`.
 *
 * The session id is a bearer credential: it is never part of a string form, a dump or a serialized copy of this
 * object, and the class has no `__toString()`.
 */
final readonly class SessionRef
{
    private string $sessionId;

    public function __construct(#[SensitiveParameter] string $sessionId)
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('A session reference needs a non-empty session id.');
        }
        $this->sessionId = $sessionId;
    }

    /**
     * The raw session id (keep it out of logs, events and exception messages).
     */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Dumps (`var_dump`, VarDumper, debug toolbars) never show the session id.
     *
     * @return array{sessionId: string}
     */
    public function __debugInfo(): array
    {
        return ['sessionId' => '[redacted]'];
    }

    /**
     * A session reference is request-scoped: it is never serialized (a queued message carries the registry hash).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new InvalidArgumentException('A session reference cannot be serialized; pass its registry hash instead.');
    }

    /**
     * A session reference is never rebuilt from a serialized string (no forged references).
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new InvalidArgumentException('A session reference cannot be unserialized.');
    }
}
