<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Storage\StorageKit;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImageTarget;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;

use function array_filter;
use function array_values;
use function in_array;

/**
 * The StorageKit file context of one owner image kind (1.18; one service per kind, tagged
 * `nowo_storage_kit.file_context`). Square images only: StorageKit's generic upload endpoint and `uploadImage()` refuse
 * it. Who writes and who reads is {@see OwnerImageAccessInterface}'s answer; a context "exists" while its subject has an
 * image (the purge then keeps nothing else of it). Subject ids are compared with `===`. Stateless.
 */
final readonly class OwnerImageFileContext implements SquareImageContextInterface
{
    public function __construct(
        private OwnerImageKind $kind,
        private OwnerImageAccessInterface $access,
        private OwnerImagePointerStoreInterface $pointers,
    ) {
    }

    public function type(): string
    {
        return $this->kind->name;
    }

    public function readable(?object $viewer, array $ids): array
    {
        $answer = $this->access->readable($viewer, $this->kind, $ids);

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $answer, true)));
    }

    public function existing(array $ids): array
    {
        $found = $this->pointers->find($this->kind->name, $ids);

        return array_values(array_filter($ids, static fn (string $id): bool => isset($found[$id])));
    }

    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        $target = OwnerImageKinds::isSubjectId($id) ? $this->access->writableTarget($user, $this->kind, $id) : null;
        try {
            return $target instanceof OwnerImageTarget ? new FileContextTarget($target->scope, $target->owner) : null;
        } catch (InvalidArgumentException) {
            return null; // a scope or owner key StorageKit refuses: fail closed
        }
    }
}
