<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Profile;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown when a profile name is not configured.
 */
final class UnknownProfileException extends InvalidArgumentException
{
    /**
     * Creates the exception for the given profile name.
     */
    public function __construct(string $profileName)
    {
        parent::__construct(sprintf('Unknown User Kit profile "%s".', $profileName));
    }
}
