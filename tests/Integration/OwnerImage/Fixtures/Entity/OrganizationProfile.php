<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 `pointer_store: column` mapping checks: an entity identified by an association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_organization_profile')]
class OrganizationProfile
{
    #[ORM\Column(name: 'cover_file_id', type: 'string', length: 64, nullable: true)]
    public ?string $coverFileId = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 64)]
    public string $slug = '';

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id')]
        public Organization $organization,
    ) {
    }
}
