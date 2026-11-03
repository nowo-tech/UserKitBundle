<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A second-factor-pending token marked only by the attribute (a package that cannot depend on UserKit).
 */
final class AttributePendingToken extends WrappingToken
{
    public function __construct(TokenInterface $inner)
    {
        parent::__construct($inner);
        $this->attributes[PendingAuthenticationTokenInterface::ATTRIBUTE] = true;
    }
}
