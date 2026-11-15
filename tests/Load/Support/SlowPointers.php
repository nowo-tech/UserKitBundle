<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\OwnerImage\Pointer\LockingOwnerImagePointerStoreInterface;

use function getenv;
use function is_numeric;
use function usleep;

/**
 * Widens the windows of an owner image upload: before the pointer is replaced (`USER_KIT_LOAD_SLOW_FIND_MS`) and after
 * it is written (`USER_KIT_LOAD_SLOW_SET_MS`), in milliseconds (none when unset). Without the subject's lock both
 * uploads would read the same previous image in that window.
 */
final readonly class SlowPointers implements LockingOwnerImagePointerStoreInterface
{
    public function __construct(
        private LockingOwnerImagePointerStoreInterface $inner,
    ) {
    }

    public function find(string $kind, array $subjectIds): array
    {
        $found = $this->inner->find($kind, $subjectIds);
        $this->pause('USER_KIT_LOAD_SLOW_FIND_MS');

        return $found;
    }

    public function set(string $kind, string $subjectId, ?string $fileId): void
    {
        $this->inner->set($kind, $subjectId, $fileId);
        $this->pause('USER_KIT_LOAD_SLOW_SET_MS');
    }

    public function replace(string $kind, string $subjectId, ?string $fileId): ?string
    {
        $this->pause('USER_KIT_LOAD_SLOW_FIND_MS');
        $previous = $this->inner->replace($kind, $subjectId, $fileId);
        $this->pause('USER_KIT_LOAD_SLOW_SET_MS');

        return $previous;
    }

    private function pause(string $variable): void
    {
        $ms = getenv($variable);
        if (is_numeric($ms) && (int) $ms > 0) {
            usleep((int) $ms * 1000);
        }
    }
}
