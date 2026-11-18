<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 `pointer_store: column`: an organization whose logos live on its own row, addressed by its public `uuid`
 * (not the identifier). The `uuid` column has no unique key on purpose: two rows of one subject id must write nothing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_organization')]
#[ORM\Index(name: 'user_kit_test_organization_uuid', columns: ['uuid'])]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(name: 'logo_file_id', type: 'string', length: 64, nullable: true)]
    private ?string $logoFileId = null;

    #[ORM\Column(name: 'logo_dark_file_id', type: 'string', length: 64, nullable: true)]
    private ?string $logoDarkFileId = null;

    #[ORM\Column(name: 'badge_file_id', type: 'string', length: 64, nullable: true)]
    private ?string $badgeFileId = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $uuid,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getLogoFileId(): ?string
    {
        return $this->logoFileId;
    }

    public function setLogoFileId(?string $logoFileId): void
    {
        $this->logoFileId = $logoFileId;
    }

    public function getLogoDarkFileId(): ?string
    {
        return $this->logoDarkFileId;
    }

    public function setLogoDarkFileId(?string $logoDarkFileId): void
    {
        $this->logoDarkFileId = $logoDarkFileId;
    }

    public function getBadgeFileId(): ?string
    {
        return $this->badgeFileId;
    }

    public function setBadgeFileId(?string $badgeFileId): void
    {
        $this->badgeFileId = $badgeFileId;
    }
}
