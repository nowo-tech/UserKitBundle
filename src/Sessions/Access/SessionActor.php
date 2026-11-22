<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Access;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;

use function preg_match;

/**
 * Since 1.23.0 (DDD-host checklist B8): who asks, by ids only — the actor's owner reference (the same {@see SessionUser}
 * the registry stores: user class and id) plus the role names to check, read from your own data at the time of the
 * check. No security user object and no entity: a host with a private entity manager, a message handler or an API
 * client builds it from what it has.
 *
 * Roles are matched exactly (`===`) after the role hierarchy of the firewall configuration, the way a security token's
 * roles are. A role is 1–255 characters without whitespace or control characters; anything else is refused, never
 * trimmed. Never build it from roles kept in a cache or a queued message (REQ-RUNTIME-003): roles change.
 */
final readonly class SessionActor
{
    /** @var list<string> */
    public array $roles;

    /**
     * @param list<string> $roles role names of the actor (e.g. `ROLE_ADMIN`)
     *
     * @throws InvalidArgumentException on an unusable role name
     */
    public function __construct(
        public SessionUser $user,
        array $roles = [],
    ) {
        foreach ($roles as $role) {
            if (preg_match('/\A[^\s\p{Cc}\p{Cf}\p{Z}]{1,255}\z/u', $role) !== 1) {
                throw new InvalidArgumentException('A session actor role is 1-255 characters without whitespace, control or format characters.');
            }
        }
        $this->roles = $roles;
    }

    /**
     * The actor of an owner reference, with these roles.
     *
     * @param list<string> $roles
     */
    public static function of(SessionUser $user, array $roles = []): self
    {
        return new self($user, $roles);
    }

    /**
     * Whether `$owner` is this actor, exactly (type and id compared with `===`, house rule LQ).
     */
    public function is(SessionUser $owner): bool
    {
        return $owner->type === $this->user->type && $owner->id === $this->user->id;
    }
}
