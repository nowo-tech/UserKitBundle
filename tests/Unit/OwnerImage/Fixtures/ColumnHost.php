<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 compile-time checks of `pointer_store: column`: an attribute-mapped entity with a mapped and an unmapped
 * property.
 */
#[ORM\Entity]
class ColumnHost
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id = 0;

    #[ORM\Column(nullable: true)]
    private ?string $logo = null;

    private ?string $plain = null;

    public function set(?string $logo, ?string $plain): void
    {
        $this->logo  = $logo;
        $this->plain = $plain;
    }

    /**
     * @return list<string|null>
     */
    public function values(): array
    {
        return [$this->logo, $this->plain];
    }
}
