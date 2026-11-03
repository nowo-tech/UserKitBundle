<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Tells pending sign-ins ({@see PendingAuthenticationTokenInterface}) from final ones.
 */
final class PendingAuthentication
{
    /**
     * Whether the token is a pending sign-in: it implements {@see PendingAuthenticationTokenInterface} or carries its
     * attribute set to `true`.
     */
    public static function isPending(?TokenInterface $token): bool
    {
        if ($token instanceof PendingAuthenticationTokenInterface) {
            return true;
        }

        return $token instanceof TokenInterface
            && $token->hasAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE)
            && $token->getAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE) === true;
    }
}
