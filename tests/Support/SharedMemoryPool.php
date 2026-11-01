<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Test double of a pool shared by every web server (a Redis / Memcached pool in production). Its class is not a
 * known per-server adapter or wrapper, so the shared-pool check accepts it; within a test kernel it is a single
 * service, which is all the "sharing" the tests need.
 */
final readonly class SharedMemoryPool implements CacheItemPoolInterface
{
    private ArrayAdapter $store;

    public function __construct()
    {
        $this->store = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->store->getItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->store->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->store->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->store->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->store->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->store->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->store->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->store->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->store->commit();
    }
}
