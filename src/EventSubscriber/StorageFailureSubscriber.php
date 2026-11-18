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
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers the exceptions UserKit raises, on every route (a session listener or the erasure may run on any of the
 * application's pages and APIs), instead of a 500. The application's own failures are left alone.
 *
 * - {@see UserKitStorageFailure} (a deadlock, a lock wait timeout, a serialization failure, a lock UserKit waits for),
 *   1.21.0 (REQ-RUNTIME-003 rule 6): **409** (configurable to 503) with the conflict code in the `X-Conflict-Code`
 *   header: `CONFLICT_001` with `Retry-After` when nothing was committed, `CONFLICT_002` without it when part of the
 *   request was.
 *   - By default the failure is replaced by a {@see ConflictHttpException} (a {@see ServiceUnavailableHttpException}
 *     at 503, as up to 1.20) carrying those headers, so the application's own error pages and JSON error format render
 *     it: their shapes are kept, the code is only added as a header.
 *   - With the platform answer (`conflict_response.platform_answer: true`, or left null while
 *     `nowo_transaction_doctrine.conflict_response.map_transient_failures` is true) the answer is {@see ConcurrencyConflictResponder::respond()}: JSON
 *     `{"error", "code", "message"}` for JSON, XHR and `/api/` requests, else the "try again" page with
 *     `data-conflict-code`, plus the same header.
 *   See {@see ConflictResponsePass} for the wiring.
 * - {@see UserKitConflict} (a domain conflict, such as a look-alike owner image subject): **409** through the
 *   application's error page, without a conflict code (it is not a lock conflict: retrying gives the same answer).
 *
 * Priority -72 (audit TH UK-3): after Symfony logged the original exception, before it renders the response, and after
 * the bundle that owns the route (AuthKit's subscriber at -64 answers a UserKit failure raised on its routes in its own
 * shape first). Stateless.
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** The response header of the conflict code (platform convention: CONFLICT_001 or CONFLICT_002 only). */
    public const CODE_HEADER = 'X-Conflict-Code';

    /** kernel.exception priority: after the route owner's mapper (AuthKit: -64), before Symfony renders (-128). */
    public const PRIORITY = -72;

    private ConflictResponsePolicy $policy;

    private ConcurrencyConflictResponder $responder;

    /**
     * @param ConflictResponsePolicy|null $policy Status (409 or 503) and Retry-After; null = 409 + Retry-After: 1
     * @param ConcurrencyConflictResponder|null $responder Builds the platform answer; null = a plain one with $policy
     * @param bool $platformAnswer Whether the platform answer replaces the application's error rendering
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        ?ConcurrencyConflictResponder $responder = null,
        private bool $platformAnswer = false,
    ) {
        $this->policy    = $policy ?? new ConflictResponsePolicy();
        $this->responder = $responder ?? new ConcurrencyConflictResponder($this->policy);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', self::PRIORITY]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if ($failure instanceof UserKitConflict) {
            $event->setThrowable(new ConflictHttpException($failure->getMessage(), $failure));

            return;
        }
        if (!$failure instanceof UserKitStorageFailure) {
            return;
        }
        $code = $failure->conflictCode();
        if ($this->platformAnswer) {
            $response = $this->responder->respond($event->getRequest(), $failure);
            // @igor-ignore - The response is built for this event by respond(), not a shared service.
            $response->headers->set(self::CODE_HEADER, $code->value);
            $event->setResponse($response);

            return;
        }
        $headers = [self::CODE_HEADER => $code->value] + ($code->retrySafe() ? $this->policy->headers() : []);
        $event->setThrowable($this->policy->status === ConflictResponsePolicy::STATUS_SERVICE_UNAVAILABLE
            ? new ServiceUnavailableHttpException(null, $failure->getMessage(), $failure, 0, $headers)
            : new ConflictHttpException($failure->getMessage(), $failure, 0, $headers));
    }
}
