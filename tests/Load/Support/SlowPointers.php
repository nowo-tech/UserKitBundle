<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;

use function getenv;
use function is_numeric;
use function usleep;

/**
 * Widens the windows of an owner image upload: after the previous image is read (`USER_KIT_LOAD_SLOW_FIND_MS`) and after
 * the new pointer is written (`USER_KIT_LOAD_SLOW_SET_MS`), in milliseconds (none when unset).
 */
final readonly class SlowPointers implements OwnerImagePointerStoreInterface
{
    public function __construct(
        private OwnerImagePointerStoreInterface $inner,
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

    private function pause(string $variable): void
    {
        $ms = getenv($variable);
        if (is_numeric($ms) && (int) $ms > 0) {
            usleep((int) $ms * 1000);
        }
    }
}
