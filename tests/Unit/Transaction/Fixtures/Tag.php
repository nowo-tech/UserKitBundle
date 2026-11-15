<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'runner_tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        public string $label,
        #[ORM\ManyToOne(targetEntity: Note::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?Note $note = null,
    ) {
    }
}
