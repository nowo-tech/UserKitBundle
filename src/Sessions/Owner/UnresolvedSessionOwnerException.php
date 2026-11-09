<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Owner;

use RuntimeException;

use function sprintf;

/**
 * A revocation was asked for a user the {@see SessionOwnerResolverInterface} cannot map to an owner
 * (`sessions.owner.on_unresolved: exception`): its sessions would otherwise stay signed in silently.
 */
final class UnresolvedSessionOwnerException extends RuntimeException
{
    public function __construct(
        public readonly string $userClass,
        public readonly string $operation,
    ) {
        parent::__construct(sprintf('NowoUserKitBundle: cannot revoke the sessions of a "%s" (%s): the session owner resolver returned no owner for it.', $userClass, $operation));
    }
}
