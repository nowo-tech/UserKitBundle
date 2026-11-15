<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use RuntimeException;

/**
 * A host's own pointer store (DDD host: the pointer lives on its own aggregates; here in memory). `$failWrites` makes
 * the next write fail.
 */
final class MemoryPointers implements OwnerImagePointerStoreInterface
{
    /** @var array<string, array<string, string>> kind => subject id => file id */
    public array $pointers = [];

    public bool $failWrites = false;

    public int $finds = 0;

    public function find(string $kind, array $subjectIds): array
    {
        ++$this->finds;
        $found = [];
        foreach ($subjectIds as $id) {
            if (isset($this->pointers[$kind][$id])) {
                $found[$id] = $this->pointers[$kind][$id];
            }
        }

        return $found;
    }

    public function set(string $kind, string $subjectId, ?string $fileId): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('pointer write failed');
        }
        if ($fileId === null) {
            unset($this->pointers[$kind][$subjectId]);

            return;
        }
        $this->pointers[$kind][$subjectId] = $fileId;
    }
}
