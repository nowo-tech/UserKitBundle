<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Storage;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;

/**
 * Where owner image files live (1.18, SPI: `owner_images.storage`; default
 * {@see StorageKit\StorageKitOwnerImageStorage} on StorageKitBundle 1.6+). The storage checks the image (type by content
 * only, size and pixel caps before decoding, re-encoding, no SVG), stores square variants under its own keys, serves
 * them with its access check and deletes them. Implement it to keep an existing file module until you move to
 * StorageKit. Keep no request state in it (worker mode).
 */
interface OwnerImageStorageInterface
{
    /**
     * Checks and stores the image; returns its file id (an orphan until {@see self::attach()}).
     *
     * @param object $actor who uploads (security user)
     *
     * @throws OwnerImageRefused before anything is stored
     */
    public function store(object $actor, OwnerImageKind $kind, string $subjectId, string $bytes, string $originalName): string;

    /**
     * Keeps `$fileId` from the orphan purge as the subject's image (null: the subject has none any more).
     */
    public function attach(OwnerImageKind $kind, string $subjectId, ?string $fileId): void;

    /**
     * Deletes a file of that subject: now (bucket call: outside your transaction), or at the next purge with
     * `$deferred` (no bucket call: safe inside a transaction). A file of another subject is never touched.
     */
    public function delete(OwnerImageKind $kind, string $subjectId, string $fileId, bool $deferred = false): void;

    /**
     * URL of the smallest stored square at least `$pixels` wide (the largest one when none is): relative path, served
     * with the storage's own read check.
     */
    public function url(string $fileId, int $pixels): string;

    /**
     * Whether a bucket is usable for that target (uploads are refused with `off` otherwise).
     */
    public function ready(OwnerImageTarget $target): bool;

    /**
     * Largest accepted upload, in bytes (the form's client-side crop aims below it).
     */
    public function maxBytes(): int;
}
