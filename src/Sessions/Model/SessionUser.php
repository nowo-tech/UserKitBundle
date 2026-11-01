<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;

use function sprintf;

/**
 * Reference of the owner of a registered session: the user class (the Doctrine root entity for mapped users) and the
 * user id (the Doctrine identifier, else the security user identifier). Built by
 * {@see SessionUserResolver}.
 */
final readonly class SessionUser
{
    /** Column length of `user_type` and `user_id`. */
    public const MAX_LENGTH = 191;

    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '' || $id === '' || mb_strlen($type) > self::MAX_LENGTH || mb_strlen($id) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('A session owner needs a non-empty type and id of at most %d characters.', self::MAX_LENGTH));
        }
    }

    /**
     * Stable key of the owner (kept in the session marker to notice a user switch).
     */
    public function key(): string
    {
        return $this->type . '#' . $this->id;
    }
}
