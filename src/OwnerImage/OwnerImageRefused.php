<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use RuntimeException;
use Throwable;

/**
 * An owner image refused before anything changed: `reason` is a stable code for the flash / error message (the
 * translations `owner_image.error.<reason>`, domain NowoUserKitBundle).
 */
final class OwnerImageRefused extends RuntimeException
{
    /** Not a PNG, JPEG or still WebP image (by content). */
    public const TYPE = 'type';

    /** Larger than the storage's limit in bytes. */
    public const SIZE = 'size';

    /** Too many pixels, or too large to decode in the memory left. */
    public const PIXELS = 'pixels';

    /** No file. */
    public const EMPTY = 'empty';

    /** Unknown kind or malformed subject id. */
    public const KIND = 'kind';

    /** The actor may not change this image. */
    public const FORBIDDEN = 'forbidden';

    /** No usable bucket for it. */
    public const OFF = 'off';

    /** The owner's storage limit is reached. */
    public const LIMIT = 'limit';

    /** The storage failed. */
    public const STORAGE = 'storage';

    /** Every reason. */
    public const REASONS = [self::TYPE, self::SIZE, self::PIXELS, self::EMPTY, self::KIND, self::FORBIDDEN, self::OFF, self::LIMIT, self::STORAGE];

    /**
     * @param string $reason one of {@see self::REASONS}
     */
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
