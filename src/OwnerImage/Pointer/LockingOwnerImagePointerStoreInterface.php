<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use Nowo\UserKitBundle\OwnerImage\OwnerImages;

/**
 * 1.19.0 (Beacon spec 239, audit QM-D-M1), optional SPI: a pointer store that tells which image it replaced, read
 * under the row lock of its own write. {@see OwnerImages} then deletes exactly that file:
 * inside an application transaction under MySQL's REPEATABLE READ a plain read before the write can return an older
 * snapshot, and the picture another request stored meanwhile would stay stored and referenced. The default
 * {@see DbalOwnerImagePointerStore} implements it; a store that only implements {@see OwnerImagePointerStoreInterface}
 * keeps the read-then-write of 1.18.
 */
interface LockingOwnerImagePointerStoreInterface extends OwnerImagePointerStoreInterface
{
    /**
     * Points the subject at `$fileId` (or at nothing) and returns the image id it pointed at, read with the row locked
     * (null when it had none).
     */
    public function replace(string $kind, string $subjectId, ?string $fileId): ?string;
}
