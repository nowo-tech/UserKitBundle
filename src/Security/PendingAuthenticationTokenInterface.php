<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A token of a sign-in that is not complete yet (e.g. AuthKit's second factor is still to be entered). UserKit does
 * not register the session of such a token: the sign-in is registered (concurrent-session limit included) on the
 * first request after the token became final.
 *
 * Packages that cannot depend on UserKit mark their token instead with the attribute {@see self::ATTRIBUTE} set to
 * `true` (see {@see PendingAuthentication::isPending()}).
 */
interface PendingAuthenticationTokenInterface extends TokenInterface
{
    /** Token attribute that marks a pending sign-in without implementing this interface. */
    public const ATTRIBUTE = 'nowo.authentication_pending';
}
