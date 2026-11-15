<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Access;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;

/**
 * Who may change and who may see owner images (1.18, SPI: `owner_images.access`; default
 * {@see DefaultOwnerImageAccess}). Implement it for organization logos and any `other` kind: the bundle has no idea who
 * manages an organization. Plain values in and out (subject ids are strings; no entity needed — DDD hosts answer by id).
 *
 * Subject ids are matched byte for byte: return them exactly as asked; the bundle compares the answer with `===`.
 * Keep no request, user or tenant state in the implementation (FrankenPHP worker mode, kernel reset off).
 */
interface OwnerImageAccessInterface
{
    /**
     * Where the image of `$subjectId` goes when `$actor` may change it; null = may not (the upload, the removal and
     * the storage answer `forbidden` / 404).
     *
     * @param object $actor security user (or your own actor object)
     */
    public function writableTarget(object $actor, OwnerImageKind $kind, string $subjectId): ?OwnerImageTarget;

    /**
     * The subject ids whose image `$viewer` may see (one query at most, whatever the number of ids).
     *
     * @param object|null $viewer security user, null = anonymous
     * @param list<string> $subjectIds
     *
     * @return list<string>
     */
    public function readable(?object $viewer, OwnerImageKind $kind, array $subjectIds): array;
}
