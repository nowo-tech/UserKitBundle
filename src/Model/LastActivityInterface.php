<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use DateTimeInterface;

/**
 * Contract for user entities that record their last authenticated activity (presence).
 */
interface LastActivityInterface
{
    /**
     * Returns when the user was last active, or null when never recorded.
     */
    public function getLastActivityAt(): ?DateTimeInterface;

    /**
     * Records the moment of the last authenticated activity.
     */
    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void;
}
