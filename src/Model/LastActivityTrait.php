<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine-mapped `lastActivityAt` column implementing {@see LastActivityInterface}.
 */
trait LastActivityTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    /**
     * Returns when the user was last active, or null when never recorded.
     */
    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    /**
     * Records the last activity, converted to an immutable date.
     */
    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->lastActivityAt = $lastActivityAt instanceof DateTimeImmutable
            ? $lastActivityAt
            : DateTimeImmutable::createFromInterface($lastActivityAt);
    }

    /**
     * Forgets the last activity (GDPR erasure).
     */
    public function clearLastActivityAt(): void
    {
        $this->lastActivityAt = null;
    }
}
