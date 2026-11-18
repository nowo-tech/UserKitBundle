<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\UserKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers the exceptions UserKit raises, on every route (a session listener or the erasure may run on any of them),
 * instead of a 500. The application's own failures are left alone.
 *
 * - {@see UserKitStorageFailure} (a deadlock, a lock wait timeout, a serialization failure, a lock UserKit waits for),
 *   1.21.0 (REQ-RUNTIME-003 rule 6): **409** (configurable to 503) with the conflict code. `CONFLICT_001` and
 *   `Retry-After` when nothing was committed; `CONFLICT_002` and no `Retry-After` when part of the request was. The
 *   answer is the platform's conflict answer ({@see ConcurrencyConflictResponder::respond()} of
 *   nowo-tech/transaction-doctrine): JSON `{"error", "code", "message"}` for JSON, XHR and `/api/` requests, else the
 *   "try again" page with `data-conflict-code`. With `conflict_response.error_page: true` the failure is replaced by an
 *   {@see HttpException} with that status and `Retry-After`, rendered by the application's error page (as up to 1.20,
 *   which answered 503). See {@see ConflictResponsePass} for the wiring.
 * - {@see UserKitConflict} (a domain conflict, such as a look-alike owner image subject): **409** through the
 *   application's error page, without a `CONFLICT_*` code (it is not a lock conflict: retrying gives the same answer).
 *
 * Priority -64: after Symfony logged the original exception, before it renders the response. Stateless.
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    private ConflictResponsePolicy $policy;

    private ConcurrencyConflictResponder $responder;

    /**
     * @param ConflictResponsePolicy|null $policy Status (409 or 503) and Retry-After; null = 409 + Retry-After: 1
     * @param ConcurrencyConflictResponder|null $responder Builds the answer; null = a plain one with $policy
     * @param bool $errorPage Whether the application's error page renders the answer (no conflict code)
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        ?ConcurrencyConflictResponder $responder = null,
        private bool $errorPage = false,
    ) {
        $this->policy    = $policy ?? new ConflictResponsePolicy();
        $this->responder = $responder ?? new ConcurrencyConflictResponder($this->policy);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if ($failure instanceof UserKitStorageFailure) {
            if ($this->errorPage) {
                $event->setThrowable(new HttpException($this->policy->status, $failure->getMessage(), $failure, $failure->committed() ? [] : $this->policy->headers()));
            } else {
                $event->setResponse($this->responder->respond($event->getRequest(), $failure));
            }
        } elseif ($failure instanceof UserKitConflict) {
            $event->setThrowable(new ConflictHttpException($failure->getMessage(), $failure));
        }
    }
}
