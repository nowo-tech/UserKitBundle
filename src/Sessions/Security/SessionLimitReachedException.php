<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Authentication failure of a sign-in refused by the `reject_new` concurrent-session policy. The message is already
 * translated (request locale, `NowoUserKitBundle` domain) so login templates that print
 * `error.messageKey|trans(error.messageData, 'security')` show it as is.
 */
final class SessionLimitReachedException extends CustomUserMessageAccountStatusException
{
}
