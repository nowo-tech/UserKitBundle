<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Event;

use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessCheckerInterface;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Since 1.23.0 (DDD-host checklist B8): dispatched by {@see OwnerImageAccessCheckerInterface} with the subject ids the
 * access service granted (never empty: no event without a grant). Ids only. A listener may only narrow: deny one owner
 * or all of them; nothing can add an owner the access service refused. Keep no state in the listener (FrankenPHP worker
 * mode, kernel reset off).
 */
final class OwnerImageIdAccessCheckEvent
{
    /** The user asks to see the images. */
    public const ACTION_VIEW = 'view';

    /** The user asks to upload or remove the image. */
    public const ACTION_CHANGE = 'change';

    /**
     * @param string|null $userId subject id of the user who asks (null = anonymous viewer)
     * @param string $kind owner image kind
     * @param string $action {@see self::ACTION_VIEW} or {@see self::ACTION_CHANGE}
     * @param list<string> $granted the owners granted so far
     */
    public function __construct(public readonly ?string $userId, public readonly string $kind, public readonly string $action, private array $granted)
    {
    }

    /**
     * The owners still granted.
     *
     * @return list<string>
     */
    public function granted(): array
    {
        return $this->granted;
    }

    /**
     * Refuses one owner (compared with `===`).
     */
    public function deny(string $ownerId): void
    {
        // @igor-ignore - Event object created per check and dispatched once; not a shared service.
        $this->granted = array_values(array_filter($this->granted, static fn (string $id): bool => $id !== $ownerId));
    }

    /**
     * Refuses every owner.
     */
    public function denyAll(): void
    {
        // @igor-ignore - Event object created per check and dispatched once; not a shared service.
        $this->granted = [];
    }

    /**
     * Whether the owner is still granted.
     */
    public function isGranted(string $ownerId): bool
    {
        return in_array($ownerId, $this->granted, true);
    }
}
