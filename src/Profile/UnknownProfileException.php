<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Profile;

use InvalidArgumentException;

use function sprintf;

final class UnknownProfileException extends InvalidArgumentException
{
    public function __construct(string $profileName)
    {
        parent::__construct(sprintf('Unknown User Kit profile "%s".', $profileName));
    }
}
