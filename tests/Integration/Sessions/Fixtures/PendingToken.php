<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface;

/**
 * A second-factor-pending token marked by the interface.
 */
final class PendingToken extends WrappingToken implements PendingAuthenticationTokenInterface
{
}
