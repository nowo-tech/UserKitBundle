<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
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
    ) {
    }

    public function upload(object $actor, string $kind, string $subjectId, string $bytes, string $originalName): OwnerImageView
    {
        $type   = $this->writable($actor, $kind, $subjectId);
        $fileId = $this->storage->store($actor, $type, $subjectId, $bytes, $originalName);
        // @igor-ignore - The closure runs synchronously while the lock is held; nothing is kept on the service.
        $previous = $this->serialised($type, $subjectId, function () use ($type, $subjectId, $fileId): ?string {
            try {
                $previous = $this->pointers->find($type->name, [$subjectId])[$subjectId] ?? null;
                // @igor-ignore - A port call (the store writes to the database); nothing is kept on the service.
                $this->pointers->set($type->name, $subjectId, $fileId);
            } catch (Throwable $e) {
                $this->logger->warning('Owner image: the {kind} pointer could not be written; the new file is removed.', ['kind' => $type->name, 'exception' => $e]);
                $this->storage->delete($type, $subjectId, $fileId);
                if ($e instanceof TransientStorageFailure) {
                    // 1.19.0: a deadlock, a lock wait or the subject's lock not obtained: 503 + Retry-After, not a refusal.
                    throw $e;
                }

                throw new OwnerImageRefused(OwnerImageRefused::STORAGE, $e);
            }
            $this->storage->attach($type, $subjectId, $fileId);

            return $previous;
        });
        if ($previous !== null && $previous !== $fileId) {
            $this->storage->delete($type, $subjectId, $previous);
        }
        $this->dispatcher?->dispatch(new OwnerImageChangedEvent($type->name, $subjectId, $fileId, $previous, $this->subjects->idOf($actor)));

        return new OwnerImageView($type->name, $subjectId, $fileId);
    }

    public function remove(object $actor, string $kind, string $subjectId): bool
    {
        $type = $this->writable($actor, $kind, $subjectId);
        // @igor-ignore - The closure runs synchronously while the lock is held; nothing is kept on the service.
        $previous = $this->serialised($type, $subjectId, function () use ($type, $subjectId): ?string {
            $previous = $this->pointers->find($type->name, [$subjectId])[$subjectId] ?? null;
            if ($previous !== null) {
                // @igor-ignore - A port call (the store writes to the database); nothing is kept on the service.
                $this->pointers->set($type->name, $subjectId, null);
                $this->storage->attach($type, $subjectId, null);
            }

            return $previous;
        });
        if ($previous === null) {
            return false;
        }
        $this->storage->delete($type, $subjectId, $previous);
        $this->dispatcher?->dispatch(new OwnerImageChangedEvent($type->name, $subjectId, null, $previous, $this->subjects->idOf($actor)));

        return true;
    }

    /**
     * 1.19.0 (Beacon spec 239): reading the subject's previous image, writing its pointer and its storage reference run
     * under a named lock of the kind and subject ({@see UserKitNamedLock}, on the connection of
     * `owner_images.doctrine.entity_manager`, else the default one). Two parallel uploads of one subject no longer both
     * read the same previous image: in 1.18.0 the file of the upload that lost was kept as referenced and never deleted.
     * Without Doctrine (a host with its own stores and no registry) nothing is locked.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function serialised(OwnerImageKind $type, string $subjectId, Closure $work): mixed
    {
        $manager = $this->doctrine?->getManager($this->lockManager);

        return $manager instanceof EntityManagerInterface
            ? UserKitNamedLock::hold($manager->getConnection(), 'owner_image:' . $type->name . ':' . $subjectId, $work)
            : $work();
    }

    public function forget(string $kind, string $subjectId): bool
    {
        $type     = $this->kinds->get($kind);
        $previous = $type instanceof OwnerImageKind && OwnerImageKinds::isSubjectId($subjectId) ? ($this->pointers->find($type->name, [$subjectId])[$subjectId] ?? null) : null;
        if (!$type instanceof OwnerImageKind || $previous === null) {
            return false;
        }
        // @igor-ignore - A port call (the store writes to the database); nothing is kept on the service.
        $this->pointers->set($type->name, $subjectId, null);
        $this->storage->delete($type, $subjectId, $previous, true);

        return true;
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
