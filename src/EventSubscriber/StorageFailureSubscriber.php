<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 1.18.0 (Beacon spec 239): a transient UserKit storage failure answers **503** with `Retry-After`, a UserKit conflict
 * **409**, instead of a 500, on every route (a session listener or the erasure may run on any of them). Only the
 * exceptions UserKit raises ({@see UserKitStorageFailure}, {@see UserKitConflict}) are mapped; the application's own
 * failures are left alone. The exception is replaced by the matching HTTP exception (generic message, the storage error
 * kept as previous for the logs), so the application's error pages and JSON error format still render it. Priority -64:
 * after Symfony logged the original exception, before it renders the response. Stateless.
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if ($failure instanceof UserKitStorageFailure) {
            $event->setThrowable(new ServiceUnavailableHttpException($failure->retryAfterSeconds(), $failure->getMessage(), $failure));
        } elseif ($failure instanceof UserKitConflict) {
            $event->setThrowable(new ConflictHttpException($failure->getMessage(), $failure));
        }
    }
}
