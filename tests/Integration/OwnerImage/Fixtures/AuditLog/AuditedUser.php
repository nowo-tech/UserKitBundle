<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditUserInterface;

/**
 * 1.20 AuditKit bridge: the user an action log of relations points at, identified by an integer `id`; its `handle`
 * holds the owner image subject id (`owner_images.audit.user_id_field: handle`). No unique key on `handle` on purpose.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_test_audited_user')]
class AuditedUser implements AuditUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $handle,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }
}
