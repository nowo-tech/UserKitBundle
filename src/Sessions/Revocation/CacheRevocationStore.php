<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Revocation;

use Psr\Cache\CacheItemPoolInterface;

use function is_string;

/**
 * One cache item per revoked session hash holding the reason, kept as long as an idle session can live
 * (`idle_lifetime` + `touch_interval`). The pool must be shared by every web server.
 */
final readonly class CacheRevocationStore implements RevocationStoreInterface
{
    private const PREFIX = 'nowo_user_kit.revoked_session.';

    /**
     * @param int $ttl marker lifetime in seconds
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $ttl,
    ) {
    }

    /**
     * Saves one marker per hash.
     */
    public function mark(array $hashes, string $reason): void
    {
        foreach ($hashes as $hash) {
            $this->cache->saveDeferred($this->cache->getItem(self::PREFIX . $hash)->set($reason)->expiresAfter($this->ttl));
        }
        $this->cache->commit();
    }

    /**
     * Reason of the marker, or null.
     */
    public function reason(string $hash): ?string
    {
        $item   = $this->cache->getItem(self::PREFIX . $hash);
        $reason = $item->isHit() ? $item->get() : null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * Deletes the marker.
     */
    public function forget(string $hash): void
    {
        $this->cache->deleteItem(self::PREFIX . $hash);
    }
}
