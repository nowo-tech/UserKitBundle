<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Limit\SessionLimitResolverInterface;

use function array_key_exists;

/**
 * Limits of one user, as returned by a {@see SessionLimitResolverInterface} (null = unlimited).
 *
 * - `maxSessions` / `policy` are enforced by the registry at sign-in.
 * - `maxTrustedDevices` is **not** enforced by the bundle (UserKit has no trusted-device store): it is carried for
 *   the host (or AuthKit) that keeps trusted devices, so one resolver serves both limits. Read it through
 *   `UserSessionManager::limitFor($user)`.
 * - `extra` holds further host-defined limits or flags (string keys), also carried untouched.
 */
final readonly class SessionLimit
{
    /**
     * @param int|null $maxSessions live sessions the user may hold at once (null = unlimited, else >= 1)
     * @param int|null $maxTrustedDevices trusted devices the user may keep (null = unlimited, else >= 0); host-enforced
     * @param array<string, mixed> $extra host-defined values (string keys), carried untouched
     */
    public function __construct(
        public ?int $maxSessions = null,
        public SessionLimitPolicy $policy = SessionLimitPolicy::EvictOldest,
        public ?int $maxTrustedDevices = null,
        public array $extra = [],
    ) {
        if ($maxSessions !== null && $maxSessions < 1) {
            throw new InvalidArgumentException('A session limit must be at least 1 (null = unlimited).');
        }
        if ($maxTrustedDevices !== null && $maxTrustedDevices < 0) {
            throw new InvalidArgumentException('A trusted-device limit must be at least 0 (null = unlimited).');
        }
    }

    /**
     * No limit.
     */
    public static function unlimited(): self
    {
        return new self();
    }

    /**
     * Whether a concurrent-session limit applies.
     */
    public function isLimited(): bool
    {
        return $this->maxSessions !== null;
    }

    /**
     * Whether a trusted-device limit applies (enforced by the host).
     */
    public function limitsTrustedDevices(): bool
    {
        return $this->maxTrustedDevices !== null;
    }

    /**
     * One host-defined value (`$default` when absent).
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->extra) ? $this->extra[$key] : $default;
    }
}
