<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;

/**
 * 1.18.0 (Beacon spec 239): a UserKit write failed transiently (deadlock, lock wait timeout, serialization failure, a
 * lock UserKit waits for) and nothing of it is pending; the same request can succeed later. It is a
 * {@see TransientStorageFailure}, so a host that maps the contract's exception already answers 503 with Retry-After;
 * UserKit's {@see StorageFailureSubscriber} does it for this subclass on every
 * route (only UserKit raises it). The message is generic; the storage error stays in the previous exception.
 */
final class UserKitStorageFailure extends TransientStorageFailure
{
}
