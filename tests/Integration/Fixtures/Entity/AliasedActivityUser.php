<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Last activity through a custom setter: the profile field (`lastActivityAt`) is not a mapped column, the setter writes
 * the mapped `seenAt` property.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_aliased_activity_user')]
class AliasedActivityUser implements UserInterface, LastActivityInterface
{
    #[ORM\Column(name: 'seen_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $seenAt = null;

    /**
     * @param non-empty-string $username
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $username,
        #[ORM\Column(name: 'display_name', type: 'string', length: 180)]
        public string $displayName = '',
    ) {
    }

    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->seenAt;
    }

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        $this->seenAt = DateTimeImmutable::createFromInterface($lastActivityAt);
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
