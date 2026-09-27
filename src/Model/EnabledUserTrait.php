<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

trait EnabledUserTrait
{
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->enabled = $enabled;

        return $this;
    }
}
