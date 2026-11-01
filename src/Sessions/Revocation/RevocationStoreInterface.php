<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Revocation;

use Nowo\UserKitBundle\Sessions\Model\SessionUser;

/**
 * Where every signed-in request looks up whether its session was revoked (`sessions.revocation.store`):
 *
 * - `database` ({@see DatabaseRevocationStore}, default): one indexed lookup of the registry row per request; works
 *   with any session handler, any number of servers and no shared cache; a missing row fails closed;
 * - `cache` ({@see CacheRevocationStore}): a marker per revoked session in a PSR-6 pool shared by every web server
 *   (`sessions.revocation.cache_pool`, required; per-server pools are refused), so requests cost no query. Markers are
 *   never deleted early: they expire after `idle_lifetime` + `touch_interval`. A lost marker (eviction, outage) is
 *   caught by the throttled registry touch: `touch_interval` is the worst-case revocation delay of this store.
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
     * Reason of a revoked session (of that owner's row), or null when it is not revoked.
     */
    public function reason(string $hash, SessionUser $owner): ?string;
}
