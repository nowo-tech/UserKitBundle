<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 `pointer_store: column` mapping checks: the root of a single table hierarchy ({@see SubTeam} is not a root),
 * with a unique column and a unique constraint (a managed entity may answer a read).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_team')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['team' => Team::class, 'sub' => SubTeam::class])]
#[ORM\UniqueConstraint(name: 'user_kit_test_team_label', columns: ['label'])]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'image_file_id', type: 'string', length: 64, nullable: true)]
    public ?string $imageFileId = null;

    /** Unique by its column mapping. */
    #[ORM\Column(name: 'code', type: 'string', length: 64, unique: true, nullable: true)]
    public ?string $code = null;

    /** Unique by a table constraint. */
    #[ORM\Column(name: 'label', type: 'string', length: 64, nullable: true)]
    public ?string $label = null;
}
