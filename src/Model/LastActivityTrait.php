<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

trait LastActivityTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->lastActivityAt = $lastActivityAt instanceof DateTimeImmutable
            ? $lastActivityAt
            : DateTimeImmutable::createFromInterface($lastActivityAt);
    }
}
