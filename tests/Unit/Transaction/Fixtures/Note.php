<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'runner_note')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'runner_note_label')]
    public Collection $labels;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
        public ?string $title,
    ) {
        $this->labels = new ArrayCollection();
    }
}
