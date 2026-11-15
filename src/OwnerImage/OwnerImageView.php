<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

/**
 * The image now stored for a subject (1.18): what {@see OwnerImagesInterface::upload()} returns. `fileId` is the
 * storage's public id of the file (StorageKit: the stored file UUID).
 */
final readonly class OwnerImageView
{
    public function __construct(
        public string $kind,
        public string $subjectId,
        public string $fileId,
    ) {
    }
}
