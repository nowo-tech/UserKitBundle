<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\EventSubscriber;

use Nowo\UserKitBundle\Transaction\ClosedManagerRecovery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * 1.18.0 (Beacon spec 239): the unit-of-work boundaries of a long-running worker (FrankenPHP with kernel reset off,
 * Messenger consumers, console commands): an entity manager a failed UserKit write closed is replaced here, before the
 * next main request and after a request, command or message ({@see ClosedManagerRecovery}). Never in the middle of a
 * request. Stateless.
 */
final readonly class UnitOfWorkBoundarySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ClosedManagerRecovery $recovery,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 4096],
            KernelEvents::TERMINATE => ['onBoundary', -4096],
            'console.terminate'     => ['onBoundary', -4096],
            // Plain names: symfony/messenger stays optional.
            WorkerMessageHandledEvent::class => ['onBoundary', -4096],
            WorkerMessageFailedEvent::class  => ['onBoundary', -4096],
        ];
    }

    /**
     * Before the main request: a manager left closed by the previous request of the worker is replaced.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->recovery->reset();
        }
    }

    /**
     * After a request, command or message.
     */
    public function onBoundary(): void
    {
        $this->recovery->reset();
    }
}
