<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Revocation;

/**
 * Where every signed-in request looks up whether its session was revoked (`sessions.revocation.store`):
 *
 * - `database` ({@see DatabaseRevocationStore}, default): one indexed lookup of the registry row per request; works
 *   with any session handler, any number of servers and no shared cache;
 * - `cache` ({@see CacheRevocationStore}): a marker per revoked session in a PSR-6 pool (`sessions.revocation.cache_pool`,
 *   which must be shared by every web server, e.g. Redis), so requests cost no query; a lost marker is caught by the
 *   throttled registry touch within `sessions.touch_interval`.
 */
interface RevocationStoreInterface
{
    /**
     * Records the revoked session hashes.
     *
     * @param list<string> $hashes
     */
    public function mark(array $hashes, string $reason): void;

    /**
     * Reason of a revoked session, or null when it is not revoked.
     */
    public function reason(string $hash): ?string;

    /**
     * Drops the record of a session that was signed out.
     */
    public function forget(string $hash): void;
}
