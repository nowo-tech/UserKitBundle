<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;

/**
 * Table `user_kit_owner_image` (1.18): the current image of each subject of each kind, for the default pointer store
 * ({@see DbalOwnerImagePointerStore}, which reads and writes it with DBAL statements). Mapped only so that your schema
 * tool / migrations create it; the bundle never loads it through an entity manager. `subject_id` gets an exact
 * (binary) collation on MySQL / MariaDB ({@see PointerCollationListener}).
 */
#[ORM\Entity]
#[ORM\Table(name: DbalOwnerImagePointerStore::TABLE)]
#[ORM\UniqueConstraint(name: 'user_kit_owner_image_subject', columns: ['kind', 'subject_id'])]
class OwnerImagePointer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine on insert)

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 16)]
        private string $kind,
        #[ORM\Column(name: 'subject_id', type: Types::STRING, length: 64)]
        private string $subjectId,
        #[ORM\Column(name: 'file_id', type: Types::STRING, length: 64)]
        private string $fileId,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
