<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Nowo\StorageKitBundle\Storage\StorageConnectionFactoryInterface;
use Nowo\StorageKitBundle\Storage\UrlPresignerInterface;

/**
 * StorageKit storage double: one in-memory bucket per bucket name; pre-signed URLs are fake.
 */
final class MemoryBuckets implements StorageConnectionFactoryInterface, UrlPresignerInterface
{
    /** @var array<string, InMemoryFilesystemAdapter> */
    private array $adapters = [];

    public function connect(S3Settings $settings): StorageConnection
    {
        $this->adapters[$settings->bucket] ??= new InMemoryFilesystemAdapter();

        return new StorageConnection(new Filesystem($this->adapters[$settings->bucket]), $this, $settings);
    }

    public function presign(string $key, string $contentType, string $disposition, DateTimeImmutable $expires): string
    {
        return 'https://bucket.storage.test/' . $key;
    }

    /**
     * Object keys of a bucket.
     *
     * @return list<string>
     */
    public function keys(string $bucket = 'avatars'): array
    {
        if (!isset($this->adapters[$bucket])) {
            return [];
        }
        $keys = [];
        foreach ((new Filesystem($this->adapters[$bucket]))->listContents('', true) as $item) {
            if ($item->isFile()) {
                $keys[] = $item->path();
            }
        }

        return $keys;
    }
}
