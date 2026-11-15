<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

/**
 * Where an owner image goes, answered by {@see Access\OwnerImageAccessInterface::writableTarget()} when the actor may
 * change it: the storage scope whose bucket holds it (null = the application bucket; with StorageKit, an organization's
 * own bucket) and the owner whose storage usage / limit it counts against (null = none). Plain strings, matched byte
 * for byte by the storage.
 */
final readonly class OwnerImageTarget
{
    /**
     * @param string|null $scope storage scope key (StorageKit: `[a-z0-9_-]`, see its ScopeResolverInterface)
     * @param string|null $owner usage owner key (StorageKit: at most 64 characters, no edge white space)
     */
    public function __construct(
        public ?string $scope = null,
        public ?string $owner = null,
    ) {
    }
}
