<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Storage\StorageKit;

use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Lifecycle\RemovedFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\SquareImageProcessor;
use Nowo\StorageKitBundle\Upload\SquareImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;

use function in_array;

/**
 * Owner images on StorageKitBundle 1.6+ (1.18): each kind is a square-only StorageKit file context of the same name
 * ({@see OwnerImageFileContext}), so StorageKit's own checks apply — PNG / JPEG / still WebP by content only (SVG, GIF,
 * HTML refused), `upload.square.max_bytes` before parsing, `upload.max_pixels` and the memory budget before decoding,
 * re-encoded WebP squares (`original`, `preview`, `thumb`: metadata and polyglot payloads dropped), random server-side
 * keys (no path traversal), the scope's bucket and the optional upload limit. Downloads go through StorageKit's route,
 * which asks the context (and so your access service) who may read. The subject's image is a StorageKit reference
 * (source type = kind, source id = subject id), so the orphan purge keeps it; a replaced image is removed at once
 * ({@see FileRemoverInterface}).
 *
 * Audit (QJ-L3): outside a transaction StorageKit records `file_uploaded` / `file_removed` / `file_expired` itself.
 * Inside one (a GDPR erasure, or a host that wraps the call), its after-commit call is held on UserKit's
 * {@see CommitQueue} (`sessions.enabled`) and runs once the transaction committed; dropped on rollback. Without the
 * queue those events are not recorded (the change itself still is: {@see OwnerImageChangedEvent}).
 * Stateless: nothing is kept on the service.
 */
final readonly class StorageKitOwnerImageStorage implements OwnerImageStorageInterface
{
    public function __construct(
        private SquareImageUploaderInterface $uploader,
        private FileReferencesInterface $references,
        private FileRemoverInterface $remover,
        private FileUrls $urls,
        private FileUris $uris,
        private StorageProfiles $profiles,
        private SquareImageProcessor $processor,
        private ?StorageEntityManager $storageManager = null,
        private ?CommitQueue $commits = null,
    ) {
    }

    /**
     * StorageKit's square upload; its refusal reasons map one to one (`context` = `forbidden`).
     */
    public function store(object $actor, OwnerImageKind $kind, string $subjectId, string $bytes, string $originalName): string
    {
        try {
            $view = $this->uploader->uploadSquareImage($actor, $kind->name, $subjectId, $bytes, $originalName);
        } catch (UploadRefused $e) {
            $reason = $e->reason === UploadRefused::CONTEXT ? OwnerImageRefused::FORBIDDEN : $e->reason;

            throw new OwnerImageRefused(in_array($reason, OwnerImageRefused::REASONS, true) ? $reason : OwnerImageRefused::STORAGE, $e);
        }
        $uploader = $this->uploader;
        if ($uploader instanceof ImageUploaderInterface) {
            $this->afterCommit(static fn () => $uploader->afterCommit($view));
        }

        return $view->uuid;
    }

    /**
     * The subject's reference points at the file (or at nothing).
     */
    public function attach(OwnerImageKind $kind, string $subjectId, ?string $fileId): void
    {
        $this->references->sync($kind->name, $subjectId, $fileId === null ? '' : '![](' . $this->uris->uri($fileId) . ')', [$kind->name, $subjectId]);
    }

    /**
     * Removed at once, or expired for the next purge.
     */
    public function delete(OwnerImageKind $kind, string $subjectId, string $fileId, bool $deferred = false): void
    {
        // @igor-ignore - Port calls (database and bucket writes); nothing is kept on the service.
        $removed = $deferred ? $this->remover->expire($fileId, $kind->name, $subjectId) : $this->remover->remove($fileId, $kind->name, $subjectId);
        $remover = $this->remover;
        if ($removed instanceof RemovedFile) {
            $this->afterCommit(static fn () => $remover->afterCommit($removed));
        }
    }

    /**
     * `thumb`, `preview` or `original`: the smallest one at least `$pixels` wide.
     */
    public function url(string $fileId, int $pixels): string
    {
        $sides   = $this->processor->sides();
        $variant = match (true) {
            $pixels <= $sides[StoredFile::VARIANT_THUMB]   => StoredFile::VARIANT_THUMB,
            $pixels <= $sides[StoredFile::VARIANT_PREVIEW] => StoredFile::VARIANT_PREVIEW,
            default                                        => StoredFile::VARIANT_ORIGINAL,
        };

        return $this->urls->download($fileId, $variant);
    }

    /**
     * Whether the target's bucket (its scope's, else the application one) is usable and GD encodes WebP here.
     */
    public function ready(OwnerImageTarget $target): bool
    {
        try {
            $profile = $this->profiles->forTarget(new FileContextTarget($target->scope, $target->owner));
        } catch (InvalidArgumentException) {
            return false;
        }

        // Audit QJ-L1: GD without WebP refuses every square image (`off`).
        return $this->processor->supported() && $profile instanceof StorageProfile && $this->profiles->settingsOf($profile) instanceof S3Settings;
    }

    /**
     * `upload.square.max_bytes`.
     */
    public function maxBytes(): int
    {
        return $this->processor->maxBytes();
    }

    /**
     * Inside a transaction of StorageKit's connection (StorageKit then skipped its audit event), holds `$record` until
     * the outermost commit on the {@see CommitQueue}.
     *
     * @param Closure(): void $record
     */
    private function afterCommit(Closure $record): void
    {
        $connection = $this->storageManager?->connection();
        if ($connection instanceof Connection && $connection->isTransactionActive()) {
            // @igor-ignore - Held on the request-scoped commit queue (reset between requests), not on this service.
            $this->commits?->hold($connection, $record);
        }
    }
}
