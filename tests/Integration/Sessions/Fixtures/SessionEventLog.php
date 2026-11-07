<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Closure;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;

/**
 * Collects the registry's audit events of a test.
 */
final class SessionEventLog
{
    /** @var list<UserSessionsRevokedEvent> */
    public array $revoked = [];

    /** @var list<UserSessionStartedEvent> */
    public array $started = [];

    /** @var (Closure(): void)|null runs on each revocation, as a host listener would */
    public ?Closure $then = null;

    public function onRevoked(UserSessionsRevokedEvent $event): void
    {
        $this->revoked[] = $event;
        if ($this->then instanceof Closure) {
            ($this->then)();
        }
    }

    public function onStarted(UserSessionStartedEvent $event): void
    {
        $this->started[] = $event;
    }

    public function clear(): void
    {
        $this->revoked = [];
        $this->started = [];
    }
}
