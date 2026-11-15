<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\Pointer\LockingOwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Transaction\UserKitNamedLock;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function max;

/**
 * Default {@see OwnerImagesInterface} (1.18), in the order that leaves nothing behind on a refusal: kind and subject id,
 * access, the storage's checks and objects, the pointer (a failed pointer write deletes the new file), the storage
 * reference, then the previous file is deleted and {@see OwnerImageChangedEvent} dispatched. Stateless: every call reads
 * the pointer store again (FrankenPHP worker mode, kernel reset off).
 */
final readonly class OwnerImages implements OwnerImagesInterface
{
    public function __construct(
        private OwnerImageKinds $kinds,
        private OwnerImageAccessInterface $access,
        private OwnerImagePointerStoreInterface $pointers,
        private OwnerImageStorageInterface $storage,
        private OwnerImageSubjects $subjects,
        private ?EventDispatcherInterface $dispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?ManagerRegistry $doctrine = null,
        private ?string $lockManager = null,
        private ?CommitQueue $commits = null,
    ) {
    }

    public function upload(object $actor, string $kind, string $subjectId, string $bytes, string $originalName): OwnerImageView
    {
        $type    = $this->writable($actor, $kind, $subjectId);
        $fileId  = $this->storage->store($actor, $type, $subjectId, $bytes, $originalName);
        $pointed = false;
        try {
            // @igor-ignore - The closure runs synchronously while the lock is held; nothing is kept on the service.
            $previous = $this->serialised($type, $subjectId, function () use ($type, $subjectId, $fileId, &$pointed): ?string {
                $previous = $this->replace($type, $subjectId, $fileId);
                $pointed  = true;
                $this->storage->attach($type, $subjectId, $fileId);

                return $previous;
            });
        } catch (Throwable $e) {
            if ($pointed) {
                throw $e;
            }
            $this->logger->warning('Owner image: the {kind} pointer could not be written; the new file is removed.', ['kind' => $type->name, 'exception' => $e]);
            $this->dispose($type, $subjectId, $fileId);
            if ($e instanceof TransientStorageFailure) {
                // 1.19.0: a deadlock, a lock wait or the subject's lock not obtained: 503 + Retry-After, not a refusal.
                throw $e;
            }

            throw new OwnerImageRefused(OwnerImageRefused::STORAGE, $e);
        }
        if ($previous !== null && $previous !== $fileId) {
            $this->dispose($type, $subjectId, $previous);
        }
        $this->dispatcher?->dispatch(new OwnerImageChangedEvent($type->name, $subjectId, $fileId, $previous, $this->subjects->idOf($actor)));

        return new OwnerImageView($type->name, $subjectId, $fileId);
    }

    public function remove(object $actor, string $kind, string $subjectId): bool
    {
        $type = $this->writable($actor, $kind, $subjectId);
        // @igor-ignore - The closure runs synchronously while the lock is held; nothing is kept on the service.
        $previous = $this->serialised($type, $subjectId, function () use ($type, $subjectId): ?string {
            $previous = $this->replace($type, $subjectId, null);
            if ($previous !== null) {
                $this->storage->attach($type, $subjectId, null);
            }

            return $previous;
        });
        if ($previous === null) {
            return false;
        }
        $this->dispose($type, $subjectId, $previous);
        $this->dispatcher?->dispatch(new OwnerImageChangedEvent($type->name, $subjectId, null, $previous, $this->subjects->idOf($actor)));

        return true;
    }

    public function forget(string $kind, string $subjectId): bool
    {
        $type = $this->kinds->get($kind);
        if (!$type instanceof OwnerImageKind || !OwnerImageKinds::isSubjectId($subjectId)) {
            return false;
        }
        $previous = $this->replace($type, $subjectId, null);
        if ($previous === null) {
            return false;
        }
        $this->storage->delete($type, $subjectId, $previous, true);

        return true;
    }

    /**
     * Points the subject at `$fileId` and returns the image it pointed at. With a
     * {@see LockingOwnerImagePointerStoreInterface} (the default store) that image is read under the row lock of the
     * write (audit QM-D-M1: inside an application transaction under MySQL's REPEATABLE READ a plain read before the
     * write can be an older snapshot, and the picture another request stored meanwhile would stay stored and
     * referenced); any other store is read, then written, as in 1.18.
     */
    private function replace(OwnerImageKind $type, string $subjectId, ?string $fileId): ?string
    {
        if ($this->pointers instanceof LockingOwnerImagePointerStoreInterface) {
            // @igor-ignore - A port call (the store writes to the database); nothing is kept on the service.
            return $this->pointers->replace($type->name, $subjectId, $fileId);
        }
        $previous = $this->pointers->find($type->name, [$subjectId])[$subjectId] ?? null;
        if ($previous !== null || $fileId !== null) {
            // @igor-ignore - A port call (the store writes to the database); nothing is kept on the service.
            $this->pointers->set($type->name, $subjectId, $fileId);
        }

        return $previous;
    }

    /**
     * 1.19.0 (Beacon spec 239): writing the subject's pointer and its storage reference run under a named lock of the kind
     * and subject ({@see UserKitNamedLock}) on the pointer's connection: the manager of `owner_images.doctrine.entity_manager`,
     * else the manager that maps the bundle's pointer entity, else the default one (audit QM-D-I1). Two parallel uploads of
     * one subject no longer both read the same previous image: in 1.18.0 the pointer and the reference could end on
     * different files. A lock not obtained in 5 s is a 503; the new file, never attached, stays an orphan for StorageKit's
     * purge. Without Doctrine (a host with its own stores and no registry) nothing is locked.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function serialised(OwnerImageKind $type, string $subjectId, Closure $work): mixed
    {
        $manager = $this->pointerManager();

        return $manager instanceof EntityManagerInterface
            ? UserKitNamedLock::hold($manager->getConnection(), 'owner_image:' . $type->name . ':' . $subjectId, $work)
            : $work();
    }

    /**
     * Deletes a file the subject no longer points at. Inside a transaction of the pointer's connection (audit QM-D-L1:
     * whatever connection the storage uses) nothing is deleted before the commit:
     * - with the commit queue (`sessions.enabled`) the deletion waits for the outermost commit and is dropped on rollback;
     *   after the commit it runs outside any transaction, so it also finds a file another request stored after the
     *   application's REPEATABLE READ snapshot (audit QM-D-M1);
     * - without it the file is expired at once (no bucket call; StorageKit's purge deletes it after the commit).
     */
    private function dispose(OwnerImageKind $type, string $subjectId, string $fileId): void
    {
        $connection = $this->pointerManager()?->getConnection();
        if (!$connection instanceof Connection || !$connection->isTransactionActive()) {
            $this->storage->delete($type, $subjectId, $fileId);

            return;
        }
        $storage = $this->storage;
        // @igor-ignore - Held on the request-scoped commit queue (reset between requests), not on this service.
        if ($this->commits?->hold($connection, static fn () => $storage->delete($type, $subjectId, $fileId)) !== true) {
            $this->storage->delete($type, $subjectId, $fileId, true);
        }
    }

    private function pointerManager(): ?EntityManagerInterface
    {
        if (!$this->doctrine instanceof ManagerRegistry) {
            return null;
        }
        $manager = $this->lockManager !== null ? $this->doctrine->getManager($this->lockManager) : ($this->doctrine->getManagerForClass(OwnerImagePointer::class) ?? $this->doctrine->getManager());

        return $manager instanceof EntityManagerInterface ? $manager : null;
    }

    public function fileIds(string $kind, array $subjectIds): array
    {
        return $this->kinds->get($kind) instanceof OwnerImageKind ? $this->pointers->find($kind, $subjectIds) : [];
    }

    public function url(string $kind, string $subjectId, int $displayedPixels = 56): ?string
    {
        $fileId = $this->fileIds($kind, [$subjectId])[$subjectId] ?? null;

        return $fileId === null ? null : $this->fileUrl($fileId, $displayedPixels);
    }

    public function fileUrl(string $fileId, int $displayedPixels = 56): string
    {
        return $this->storage->url($fileId, 2 * max(1, $displayedPixels));
    }

    public function ready(object $actor, string $kind, string $subjectId): bool
    {
        $type   = $this->kinds->get($kind);
        $target = $type instanceof OwnerImageKind && OwnerImageKinds::isSubjectId($subjectId) ? $this->access->writableTarget($actor, $type, $subjectId) : null;

        return $target instanceof OwnerImageTarget && $this->storage->ready($target);
    }

    public function maxBytes(): int
    {
        return $this->storage->maxBytes();
    }

    public function kinds(): OwnerImageKinds
    {
        return $this->kinds;
    }

    /**
     * @throws OwnerImageRefused
     */
    private function writable(object $actor, string $kind, string $subjectId): OwnerImageKind
    {
        $type = $this->kinds->get($kind);
        if (!$type instanceof OwnerImageKind || !OwnerImageKinds::isSubjectId($subjectId)) {
            throw new OwnerImageRefused(OwnerImageRefused::KIND);
        }
        if (!$this->access->writableTarget($actor, $type, $subjectId) instanceof OwnerImageTarget) {
            throw new OwnerImageRefused(OwnerImageRefused::FORBIDDEN);
        }

        return $type;
    }
}
