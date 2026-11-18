<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\UserKitBundle\EventSubscriber\StorageFailureSubscriber;

/**
 * 1.19.0 (Beacon spec 239): a UserKit write failed transiently (deadlock, lock wait timeout, serialization failure, a
 * lock UserKit waits for) and nothing of it is pending; the same request can succeed later. It is a
 * {@see TransientStorageFailure}, so code that already handles the contract's exception keeps working; UserKit's
 * {@see StorageFailureSubscriber} answers it on every route (only UserKit raises it). The message is generic; the
 * storage error stays in the previous exception.
 *
 * 1.21.0 (REQ-RUNTIME-003 rule 6): it carries a conflict code ({@see self::conflictCode()}): `CONFLICT_001` when nothing
 * of the request was committed (the answer has `Retry-After`), `CONFLICT_002` when the request had already committed
 * part of its work before the failure ({@see self::afterCommit()}: an eviction before a failed registration, a revocation
 * next to a failed remember-me epoch bump), answered without `Retry-After`: read again before repeating.
 */
final class UserKitStorageFailure extends TransientStorageFailure
{
    /**
     * The same failure, marked as coming after part of the request was committed (`CONFLICT_002`, no `Retry-After`).
     * The storage error stays in the previous chain, behind a committed {@see ConcurrencyConflict}, so the conflict
     * responder of nowo-tech/transaction-doctrine gives the same code when it answers instead of UserKit.
     */
    public static function afterCommit(self $failure): self
    {
        if ($failure->committed()) {
            return $failure;
        }
        $cause = $failure->getPrevious() ?? $failure;
        $kind  = (new TransientFailureClassifier())->classify($cause) ?? TransientFailureKind::Retryable;

        return new self(null, $failure->getMessage(), new ConcurrencyConflict(1, $kind, null, $cause, true));
    }

    /**
     * The conflict code of the answer: {@see ConflictCode::Committed} after {@see self::afterCommit()}, else
     * {@see ConflictCode::RetrySafe}.
     */
    public function conflictCode(): ConflictCode
    {
        return ConflictCode::forFailure($this);
    }

    /**
     * Whether part of the request was committed before the failure (see {@see self::afterCommit()}).
     */
    public function committed(): bool
    {
        return $this->conflictCode() === ConflictCode::Committed;
    }
}
