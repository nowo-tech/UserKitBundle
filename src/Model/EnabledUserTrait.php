<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine-mapped `enabled` flag implementing {@see AccountStatusInterface}.
 */
trait EnabledUserTrait
{
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /**
     * Whether the account is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables or disables the account.
     */
    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->enabled = $enabled;

        return $this;
    }
}
