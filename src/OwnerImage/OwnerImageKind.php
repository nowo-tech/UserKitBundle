<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use InvalidArgumentException;

use function in_array;
use function preg_match;

/**
 * One kind of owner image (1.18, `owner_images.kinds`): a user's profile picture (`avatar`), an organization's logo
 * (`org_logo`), its dark-theme logo… Plain value object (no framework type).
 *
 * - `name`: 1–16 characters `[a-z0-9_]`; also the StorageKit file context type and reference source type.
 * - `subject`: `user` (the subject id is a user's id: the default access lets each user change their own image, and the
 *   GDPR eraser / export cover it) or `other` (organizations, projects…: your {@see Access\OwnerImageAccessInterface}
 *   decides who changes it).
 * - `read`: who may see the stored image through the download route: `public` (anyone, anonymous included),
 *   `authenticated` (any signed-in user) or `host` (your access service decides).
 * - `shape`: the crop frame of the form field, `circle` or `square` (the stored image is always a square).
 */
final readonly class OwnerImageKind
{
    /** Name syntax. */
    public const NAME_PATTERN = '/^[a-z0-9_]{1,16}$/';

    public const SUBJECT_USER = 'user';

    public const SUBJECT_OTHER = 'other';

    public const READ_PUBLIC = 'public';

    public const READ_AUTHENTICATED = 'authenticated';

    public const READ_HOST = 'host';

    public const SHAPE_CIRCLE = 'circle';

    public const SHAPE_SQUARE = 'square';

    /**
     * @throws InvalidArgumentException on a malformed name or an unknown subject, read or shape value
     */
    public function __construct(
        public string $name,
        public string $subject = self::SUBJECT_OTHER,
        public string $read = self::READ_AUTHENTICATED,
        public string $shape = self::SHAPE_SQUARE,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('An owner image kind name is 1-16 characters [a-z0-9_].');
        }
        if (!in_array($subject, [self::SUBJECT_USER, self::SUBJECT_OTHER], true)
            || !in_array($read, [self::READ_PUBLIC, self::READ_AUTHENTICATED, self::READ_HOST], true)
            || !in_array($shape, [self::SHAPE_CIRCLE, self::SHAPE_SQUARE], true)) {
            throw new InvalidArgumentException('An owner image kind has subject user|other, read public|authenticated|host and shape circle|square.');
        }
    }

    /**
     * Whether the subject id is a user's id.
     */
    public function isUserKind(): bool
    {
        return $this->subject === self::SUBJECT_USER;
    }
}
