<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;
use RuntimeException;
use Throwable;

/**
 * 1.19.0 (Beacon spec 239): a domain conflict UserKit cannot resolve by reading the winner's row: an owner image
 * subject whose unique key a look-alike subject already holds (a pointer table not on the binary collation), or several
 * rows for one subject id. Nothing of the write is pending. {@see StorageFailureSubscriber} answers 409 through the
 * application's error page, with no `CONFLICT_*` code: it is not a lock conflict (REQ-RUNTIME-003 rule 6). The message
 * is generic; the storage error, if any, stays in the previous exception.
 *
 * 1.21.0: a unique violation at the end of a unit of work is no longer turned into this exception (it is rethrown as
 * it is: UserKit's own "only one" writes resolve their races where they happen).
 */
final class UserKitConflict extends RuntimeException
{
    public const DEFAULT_MESSAGE = 'The data changed meanwhile. Reload and try again.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::DEFAULT_MESSAGE, 0, $previous);
    }
}
