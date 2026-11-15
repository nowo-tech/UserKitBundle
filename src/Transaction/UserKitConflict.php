<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use RuntimeException;
use Throwable;

/**
 * 1.18.0 (Beacon spec 239): a UserKit unit of work lost a unique key race that it cannot resolve by reading the winner's
 * row (for example an erasure whose anonymised value another request stored first). Nothing of the unit is pending;
 * the caller reloads and tries again. {@see StorageFailureSubscriber} answers 409.
 * The message is generic; the storage error stays in the previous exception.
 */
final class UserKitConflict extends RuntimeException
{
    public const DEFAULT_MESSAGE = 'The data changed meanwhile. Reload and try again.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::DEFAULT_MESSAGE, 0, $previous);
    }
}
