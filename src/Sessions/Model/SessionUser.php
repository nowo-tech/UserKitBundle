<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;

use function explode;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Reference of the owner of a registered session: the user class (the Doctrine root entity for mapped users) and the
 * user id (the Doctrine identifier, else the security user identifier). Built by
 * {@see SessionUserResolver}.
 *
 * House rule LQ (look-alike owners): the type and the id are valid UTF-8 without leading or trailing whitespace and
 * without control, format or line / paragraph separator characters anywhere. Such ids are refused, never trimmed: on a
 * PAD SPACE collation `alice ` would otherwise match `alice`, and invisible characters make look-alikes. Case and
 * accents stay significant (`Alice` and `alice` are two owners); the store matches them exactly.
 */
final readonly class SessionUser
{
    /** Column length of `user_type` and `user_id`. */
    public const MAX_LENGTH = 191;

    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (!self::isUsable($type) || !self::isUsable($id)) {
            throw new InvalidArgumentException(sprintf('A session owner needs a type and an id of 1 to %d characters, without leading or trailing whitespace and without control or format characters.', self::MAX_LENGTH));
        }
    }

    /**
     * Whether `$value` can be an owner type or id: 1 to {@see self::MAX_LENGTH} characters of valid UTF-8, no leading
     * or trailing whitespace (Unicode included), no control (Cc), format (Cf, e.g. zero-width space), line or paragraph
     * separator (Zl, Zp) character anywhere.
     */
    public static function isUsable(string $value): bool
    {
        return $value !== ''
            && preg_match('/\A(?![\s\p{Z}])(?:[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}])*(?<![\s\p{Z}])\z/u', $value) === 1
            && mb_strlen($value) <= self::MAX_LENGTH;
    }

    /**
     * Owner of a {@see self::key()}, or null when the key is malformed.
     */
    public static function fromKey(string $key): ?self
    {
        $parts = explode('#', $key, 2);
        try {
            return new self($parts[0], $parts[1] ?? '');
        } catch (InvalidArgumentException) {
            return null;
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
