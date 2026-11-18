<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 `pointer_store: column` mapping checks: a composite identifier (no default subject field), and date, guid and
 * bigint columns (a subject id the type refuses has no row).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_composite_owner')]
class CompositeOwner
{
    #[ORM\Column(name: 'image_file_id', type: 'string', length: 64, nullable: true)]
    public ?string $imageFileId = null;

    #[ORM\Column(name: 'founded', type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $founded = null;

    #[ORM\Column(name: 'reference', type: 'guid', nullable: true)]
    public ?string $reference = null;

    #[ORM\Column(name: 'serial', type: 'bigint', nullable: true)]
    public int|string|null $serial = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public string $tenant,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public string $code,
    ) {
    }
}
