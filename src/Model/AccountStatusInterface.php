<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

interface AccountStatusInterface
{
    public function isEnabled(): bool;
}
