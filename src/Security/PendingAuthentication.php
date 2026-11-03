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
     * Whether the token is a pending sign-in: it implements {@see PendingAuthenticationTokenInterface}, or it has no
     * roles and carries the attribute set to `true`.
     */
    public static function isPending(?TokenInterface $token): bool
    {
        if ($token instanceof PendingAuthenticationTokenInterface) {
            return true;
        }

        // DC-L1: the attribute counts only on a role-less token (a pending sign-in grants nothing): a token with roles
        // that kept the attribute by mistake is registered like any session (limits, idle expiry, revocation).
        return $token instanceof TokenInterface
            && $token->getRoleNames() === []
            && $token->hasAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE)
            && $token->getAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE) === true;
    }
}
