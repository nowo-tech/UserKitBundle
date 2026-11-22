<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use DateTimeImmutable;

/**
 * Dispatched after an owner image was stored, replaced or removed by {@see OwnerImagesInterface::upload()} /
 * {@see OwnerImagesInterface::remove()} (1.18), once the pointer is written (the bundle's own DBAL write, committed
 * unless you wrapped the call in your transaction). Carries ids only (architecture bar rule 4): record your audit trail
 * from it. Not dispatched by {@see OwnerImagesInterface::forget()} (erasures run inside a transaction).
 * Since 1.23.0: `occurredAt`, when the pointer was written.
 */
final readonly class OwnerImageChangedEvent
{
    /** Since 1.23.0: when the pointer was written (the construction time when not given). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param string|null $fileId the new image, null when it was removed
     * @param string|null $previousFileId the replaced or removed image, null when there was none
     * @param string|null $actorId the actor's subject id ({@see OwnerImageSubjects::idOf()}), null when unknown
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the pointer was written (null = now)
     */
    public function __construct(
        public string $kind,
        public string $subjectId,
        public ?string $fileId,
        public ?string $previousFileId,
        public ?string $actorId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
