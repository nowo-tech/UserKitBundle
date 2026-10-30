<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

/**
 * Contract for user entities that can be enabled or disabled (checked at authentication).
 */
interface AccountStatusInterface
{
    /**
     * Whether the account is enabled; disabled accounts cannot authenticate.
     */
    public function isEnabled(): bool;
}
