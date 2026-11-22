<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Event;

use DateTimeImmutable;
use Nowo\UserKitBundle\PersonalData\PersonalDataErasureInterface;

/**
 * 1.22.0: dispatched once per committed erasure of {@see PersonalDataErasureInterface::erase()}, **after** the transaction
 * that holds it commits. Listen to it to act on what must not join that transaction: another bundle that writes in
 * transactions of its own (and refuses to run inside a foreign one), a remote API, a message.
 *
 * When it fires:
 * - the erasure ran in UserKit's own transaction: when `erase()` returns, after the commit;
 * - the erasure ran inside a transaction the application opened (a savepoint of it): after the application's outermost
 *   commit, at the next boundary (Doctrine's `postFlush`, `kernel.response`, `kernel.terminate`, `console.terminate`,
 *   the end of a Messenger message, `kernel.reset`). Never on a rollback (of that transaction, or of a savepoint enclosing
 *   the erasure, as DBAL sends it), a failed commit or a connection lost before the commit. A unit of work the
 *   application replays (a retry of transaction-doctrine's runner, a nested unit retried inside an open transaction)
 *   dispatches it once, for the attempt that committed.
 *
 * A listener that throws is logged (error level) and the next listener still runs: the erasure is committed and
 * `erase()` neither throws nor returns anything else because of it. Your listener must therefore be idempotent and
 * catch-and-retry what it cannot afford to lose (a durable message is the usual way). Stopping propagation is not
 * supported: every listener runs. Do not flush the user's entity manager from a listener (it may run in that manager's
 * `postFlush`): use your own manager or connection, or a message.
 *
 * Personal data: `originalIdentifier` (the security identifier before any eraser ran, often an e-mail) is kept for the
 * correlation of data keyed by it. Do not log or store it. `userType` / `userId` are the Doctrine root class and
 * identifier (the same owner reference as the session registry; the identifier when the user is not a mapped entity;
 * personal data too when the primary key is the e-mail or another personal value). `user` is the entity as the erasers
 * left it (already anonymised, possibly removed): read your own correlation id from it (e.g. a UUID), never personal
 * fields. `context` is the free array passed to `erase()` (e.g. a tenant, a reason code): never stored nor logged by the
 * bundle, keep secrets out of it.
 *
 * Since 1.23.0 (DDD-host checklist B4) the event carries ids for a host that keeps no user object: `userType` / `userId`
 * (above), `actorType` / `actorId` (the actor's owner reference, captured before the erasers ran; null without an actor
 * or when it cannot be mapped) and `occurredAt` (the same instant as `erasedAt`). The `user` object is deprecated.
 */
final readonly class AccountErasedEvent
{
    /** Since 1.23.0: when the erasure happened (equals `erasedAt`). */
    public DateTimeImmutable $occurredAt;

    /**
     * @param object $user the erased user entity, as the erasers left it
     * @param string|null $userType Doctrine root class of the user (captured before the erasers ran); null when unknown
     * @param string|null $userId Doctrine identifier of the user (captured before the erasers ran); null when unknown
     * @param string|null $originalIdentifier security identifier before any eraser ran (personal data: correlation only)
     * @param list<string> $erasers keys of the erasers that ran, in order
     * @param DateTimeImmutable $erasedAt when the erasers' unit of work succeeded
     * @param bool $selfService true without an actor or when the actor is the user
     * @param array<string, mixed> $context the free host context passed to `erase()`
     * @param string|null $actorType Since 1.23.0: Doctrine root class of the actor (captured before the erasers ran); null without an actor or when unknown
     * @param string|null $actorId Since 1.23.0: Doctrine identifier of the actor (captured before the erasers ran); null without an actor or when unknown
     * @param DateTimeImmutable|null $occurredAt Since 1.23.0: when the erasure happened (null = `erasedAt`)
     */
    public function __construct(
        /** @deprecated since 1.23.0, read {@see self::$userType} / {@see self::$userId}; removed in 2.0 */
        public object $user,
        public ?string $userType,
        public ?string $userId,
        public ?string $originalIdentifier,
        public array $erasers,
        public DateTimeImmutable $erasedAt,
        public bool $selfService,
        public array $context = [],
        public ?string $actorType = null,
        public ?string $actorId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? $erasedAt;
    }
}
