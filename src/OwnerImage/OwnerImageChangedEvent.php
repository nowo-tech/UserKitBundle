<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

/**
 * Dispatched after an owner image was stored, replaced or removed by {@see OwnerImagesInterface::upload()} /
 * {@see OwnerImagesInterface::remove()} (1.18), once the pointer is written (the bundle's own DBAL write, committed
 * unless you wrapped the call in your transaction). Carries ids only (architecture bar rule 4): record your audit trail
 * from it. Not dispatched by {@see OwnerImagesInterface::forget()} (erasures run inside a transaction).
 */
final readonly class OwnerImageChangedEvent
{
    /**
     * @param string|null $fileId the new image, null when it was removed
     * @param string|null $previousFileId the replaced or removed image, null when there was none
     * @param string|null $actorId the actor's subject id ({@see OwnerImageSubjects::idOf()}), null when unknown
     */
    public function __construct(
        public string $kind,
        public string $subjectId,
        public ?string $fileId,
        public ?string $previousFileId,
        public ?string $actorId,
    ) {
    }
}
