<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

/**
 * Where each subject's current image id is kept (1.18, SPI: `owner_images.pointer_store`; default
 * {@see DbalOwnerImagePointerStore}, the bundle's own table). Implement it to keep the pointer on your own rows (a
 * `avatar_file_uuid` column on the user, two logo columns on the organization): lists then need no extra query.
 *
 * Subject ids are matched byte for byte (house rule LQ): return them exactly as asked. Write with your own explicit
 * transaction or DBAL statements; never flush an entity manager you do not own (architecture bar rule 6). Keep no
 * request state (FrankenPHP worker mode).
 */
interface OwnerImagePointerStoreInterface
{
    /**
     * The current image id of each subject that has one.
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, string> subject id => file id (PHP turns numeric-string keys into integers: look ids up
     *                               with `isset($found[$id])` / `$found[$id]`, never compare keys with `===`)
     */
    public function find(string $kind, array $subjectIds): array;

    /**
     * Points the subject at `$fileId`, or at nothing (null).
     */
    public function set(string $kind, string $subjectId, ?string $fileId): void;
}
