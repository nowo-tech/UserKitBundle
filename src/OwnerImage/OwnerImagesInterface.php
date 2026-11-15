<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

/**
 * Owner images (1.18, public port; service {@see OwnerImages}): one square image per subject and kind — a user's profile
 * picture, an organization's logo (light and dark), a project's icon… Strings in, value objects out (no entity, no
 * HttpFoundation type): call it from a controller after your own CSRF / rate-limit checks, or from an application
 * service of a DDD host.
 */
interface OwnerImagesInterface
{
    /**
     * Stores `$bytes` as the subject's image and drops the previous one at once (its bucket objects: call it outside
     * your own transaction). Checks, in order: kind and subject id, the actor may change it
     * ({@see Access\OwnerImageAccessInterface}), the storage's image checks and limits.
     *
     * @param object $actor who uploads (security user)
     *
     * @throws OwnerImageRefused before anything changed
     */
    public function upload(object $actor, string $kind, string $subjectId, string $bytes, string $originalName): OwnerImageView;

    /**
     * Removes the subject's image (same access check). False when it had none.
     *
     * @throws OwnerImageRefused unknown kind, malformed subject id or not allowed
     */
    public function remove(object $actor, string $kind, string $subjectId): bool;

    /**
     * Drops the subject's image without an access check and without a bucket call (the next purge deletes the file):
     * for GDPR erasures and deleted subjects, inside your transaction. False when it had none or the kind is unknown.
     */
    public function forget(string $kind, string $subjectId): bool;

    /**
     * The current file id of each subject that has an image (one pointer-store call).
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, string> subject id => file id (numeric ids become integer keys: look them up with isset())
     */
    public function fileIds(string $kind, array $subjectIds): array;

    /**
     * URL of the subject's image for a displayed width in CSS pixels (a square at least twice as wide: sharp on
     * high-density screens), or null when it has none.
     */
    public function url(string $kind, string $subjectId, int $displayedPixels = 56): ?string;

    /**
     * The URL of a known file id for a displayed width (no pointer lookup: lists that preloaded the ids).
     */
    public function fileUrl(string $fileId, int $displayedPixels = 56): string;

    /**
     * Whether `$actor` could upload this image now: allowed, and a bucket is usable for it.
     */
    public function ready(object $actor, string $kind, string $subjectId): bool;

    /**
     * Largest accepted upload, in bytes.
     */
    public function maxBytes(): int;

    /**
     * The configured kinds.
     */
    public function kinds(): OwnerImageKinds;
}
