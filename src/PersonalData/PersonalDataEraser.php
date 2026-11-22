<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\PersonalData\Event\AccountErasedEvent;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function assert;
use function is_callable;
use function sprintf;

/**
 * Runs the erasure guards, then every supporting eraser in one Doctrine transaction (flushed once), through UserKit's
 * own transaction runner ({@see UserKitTransactionRunner}, 1.19.0): its own transaction, or a savepoint of the host's
 * when one is already open on the user's entity manager.
 *
 * 1.22.0: dispatches {@see AccountErasedEvent} once the erasure is committed (when `erase()` returns, or after the
 * outermost commit of the application's transaction), never on a rollback; a listener that throws is logged and the
 * others still run. Stateless (worker safe): an erasure waiting for the application's commit is held by UserKit's
 * internal commit queue, which drops it at `kernel.reset` when that transaction never committed.
 */
final readonly class PersonalDataEraser implements PersonalDataErasureInterface
{
    private UserKitTransactionRunner $runner;

    /**
     * Receives the tagged erasers and guards plus the Doctrine registry.
     *
     * @param iterable<PersonalDataEraserInterface> $erasers ordered by priority
     * @param iterable<ErasureGuardInterface> $guards
     */
    public function __construct(
        private iterable $erasers,
        private iterable $guards,
        private ManagerRegistry $doctrine,
        private ?CommitQueue $commits = null,
        ?UserKitTransactionRunner $runner = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
    ) {
        $this->runner = $runner ?? new UserKitTransactionRunner($doctrine);
    }

    /**
     * Pre-flight for the UI ("can this account be erased?"): every veto, nothing erased.
     *
     * @param array<string, mixed> $context
     *
     * @return list<ErasureVeto>
     */
    public function check(object $user, ?object $actor = null, array $context = []): array
    {
        return $this->vetoes($this->request($user, $actor, $context));
    }

    /**
     * Runs the guards, then every supporting eraser in one transaction, flushing once. Inside an open host transaction
     * the erasers nest in it (a savepoint; the host commits or rolls back, and a failed erasure marks it rollback-only);
     * otherwise the eraser opens and commits its own.
     *
     * @param array<string, mixed> $context
     *
     * @throws ErasureVetoedException when a guard vetoes (nothing is erased)
     * @throws UserKitStorageFailure when the storage failed transiently (deadlock, lock wait; nothing is erased)
     * @throws UserKitConflict when an eraser raised one (nothing is erased)
     * @throws LogicException when the user class has no ORM entity manager, or (1.22.0) when an event dispatcher is set
     *                        and a transaction is open on its connection that UserKit cannot follow to its commit (nothing
     *                        is erased)
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    public function erase(object $user, ?object $actor = null, array $context = []): array
    {
        // 1.12: the identifier is captured before any eraser can anonymise it.
        $request = $this->request($user, $actor, $context);
        $vetoes  = $this->vetoes($request);
        if ($vetoes !== []) {
            throw new ErasureVetoedException($vetoes);
        }

        $entityManager = $this->doctrine->getManagerForClass($user::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager manages "%s".', $user::class));
        }

        // 1.19.0 (Beacon spec 239): one unit of work of UserKit's own runner, flushed once inside its transaction. Inside
        // an open host transaction it nests (a savepoint): the host commits or rolls back the rest. After a failure no
        // level of the runner stays open, the manager is neither reset nor cleared (only the rows the erasure touched
        // are detached; a manager a failed flush closed is replaced at the next boundary), a deadlock or lock wait is a
        // UserKitStorageFailure (409 + CONFLICT_001 since 1.21.0); a unique violation of your erasers is rethrown as it is.
        $connection = $entityManager->getConnection();
        if ($this->dispatcher instanceof EventDispatcherInterface && $connection->isTransactionActive() && $this->commits?->watches($connection) !== true) {
            // 1.22.0: AccountErasedEvent promises "after the commit"; a transaction UserKit cannot see commit would leave
            // it nothing to wait for. Refused before anything changes.
            throw new LogicException('NowoUserKitBundle: the personal data erasure runs inside a transaction whose commit UserKit cannot follow (no commit queue, or the connection is not in the Doctrine registry): AccountErasedEvent could not be dispatched after the commit.');
        }
        // The owner reference is read before any eraser runs: a removed entity loses its generated id at the flush.
        $owner = $this->dispatcher instanceof EventDispatcherInterface ? (new SessionUserResolver($this->doctrine))->resolve($user) : null;
        // 1.23.0 (DDD-host checklist B4): the actor's reference too, for hosts that keep no user object.
        $actorRef = $this->dispatcher instanceof EventDispatcherInterface && $actor !== null ? $this->actorReference($actor) : null;
        try {
            // @igor-ignore - Closure runs synchronously inside the runner; it is not stored on the service.
            $ran = $this->runner->runFor($entityManager, fn (): array => $this->runErasers($request));
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                // The host's transaction is still open: it must not commit without the erasure (1.18 behaviour). The
                // rollback-only flag is cleared only by the outermost rollBack(), not by Connection::close(): the host
                // must roll back (not just close) after a failed erasure, or its next commit on this connection throws
                // CommitFailedRollbackOnly.
                // @igor-ignore - marks the host's open transaction, which the host must roll back (see above)
                $connection->setRollbackOnly();
            }

            throw $exception;
        }
        $event = $this->dispatcher instanceof EventDispatcherInterface ? new AccountErasedEvent(
            $request->user,
            $owner?->type,
            $owner?->id,
            $request->originalIdentifier,
            $ran,
            $this->clock?->now() ?? new DateTimeImmutable(),
            $request->isSelfService(),
            $request->context,
            $actorRef?->type,
            $actorRef?->id,
        ) : null;
        if ($event instanceof AccountErasedEvent && $connection->isTransactionActive()) {
            // 1.22.0: inside the application's transaction (watched, checked above) the event waits for its outermost
            // commit; it is dropped on a rollback (of that transaction, or of a savepoint enclosing the erasure), a failed
            // commit or kernel.reset.
            // @igor-ignore - the queue holds the closure until that commit; nothing is kept on this service
            if ($this->commits?->hold($connection, fn () => $this->dispatchErased($event)) !== true) {
                // Audit VT-L1: the queue stopped following the transaction during the erasure (it ended unseen and a new
                // one began): no commit can be awaited, so the event is not dispatched, loudly. Audit VT2-I1: the user type only, the
                // id may be personal data (an e-mail primary key).
                $this->logger?->error('NowoUserKitBundle: AccountErasedEvent was not dispatched: the transaction of the erasure can no longer be followed to its commit.', ['user_type' => $event->userType]);
            }
            $event = null;
        }
        // Marks in the revocation cache of the session eraser: what was erased is committed now, not at the end of the
        // request. Then, when the erasure ran in UserKit's own transaction, AccountErasedEvent (committed by now). Audit
        // VT-M2: an earlier committed effect that throws from the release does not cost the event; it goes on afterwards.
        try {
            $this->commits?->release();
        } finally {
            if ($event instanceof AccountErasedEvent) {
                $this->dispatchErased($event);
            }
        }

        return $ran;
    }

    /**
     * Runs every listener of {@see AccountErasedEvent}: the erasure is committed, so a listener that throws is logged
     * and the next one still runs (nothing is rethrown). With a dispatcher that cannot list its listeners, one dispatch
     * whose failure is logged.
     */
    private function dispatchErased(AccountErasedEvent $event): void
    {
        if (!$this->dispatcher instanceof SymfonyEventDispatcherInterface) {
            try {
                $this->dispatcher?->dispatch($event);
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }

            return;
        }
        foreach ($this->dispatcher->getListeners(AccountErasedEvent::class) as $listener) {
            // getListeners() of one event name is a flat list of callables.
            assert(is_callable($listener));
            try {
                $listener($event, AccountErasedEvent::class, $this->dispatcher);
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    /**
     * Audit YQ-L2 (1.23.1): the actor's reference is informative; an actor the resolver cannot read (a throwing metadata
     * lookup) leaves it null instead of refusing the erasure. Only the exception class is logged.
     */
    private function actorReference(object $actor): ?SessionUser
    {
        try {
            return (new SessionUserResolver($this->doctrine))->resolve($actor);
        } catch (Throwable $exception) {
            $this->logger?->warning('NowoUserKitBundle: the reference of the actor of a personal data erasure could not be resolved; AccountErasedEvent carries no actor reference.', ['exception' => $exception::class]);

            return null;
        }
    }

    private function logFailure(Throwable $exception): void
    {
        $this->logger?->error('NowoUserKitBundle: a listener of AccountErasedEvent failed after the erasure committed; the erasure stays committed.', ['exception' => $exception]);
    }

    /**
     * Every supporting eraser (the runner flushes once afterwards).
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    private function runErasers(ErasureRequest $request): array
    {
        $ran = [];
        foreach ($this->erasers as $eraser) {
            if (!$eraser->supports($request->user)) {
                continue;
            }

            $eraser->erase($request);
            $ran[] = $eraser->getKey();
        }

        return $ran;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function request(object $user, ?object $actor, array $context): ErasureRequest
    {
        return new ErasureRequest($user, $actor, $context, $user instanceof UserInterface ? $user->getUserIdentifier() : null);
    }

    /**
     * @return list<ErasureVeto>
     */
    private function vetoes(ErasureRequest $request): array
    {
        $vetoes = [];
        foreach ($this->guards as $guard) {
            $veto = $guard->check($request);
            if ($veto instanceof ErasureVeto) {
                $vetoes[] = $veto;
            }
        }

        return $vetoes;
    }
}
