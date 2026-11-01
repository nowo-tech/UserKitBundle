<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Profile user of the session registry tests (generated integer id, roles, plaintext password).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_member')]
class Member implements UserInterface, PasswordAuthenticatedUserInterface, AccountStatusInterface
{
    use EnabledUserTrait;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 180, unique: true)]
        private string $email,
        #[ORM\Column(type: 'string')]
        private string $password,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column(type: 'integer')]
        private ?int $id = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous';
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function eraseCredentials(): void
    {
    }
}
