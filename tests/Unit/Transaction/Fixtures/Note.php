<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'runner_note')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
        public ?string $title,
    ) {
    }
}
