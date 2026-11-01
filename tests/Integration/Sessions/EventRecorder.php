<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Countable;
use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use PHPUnit\Framework\Assert;

use function count;

/**
 * Collects the {@see SessionLimitReachedEvent}s of a test.
 */
final class EventRecorder implements Countable
{
    /** @var list<SessionLimitReachedEvent> */
    private array $events = [];

    public function record(SessionLimitReachedEvent $event): void
    {
        $this->events[] = $event;
    }

    public function first(): SessionLimitReachedEvent
    {
        Assert::assertArrayHasKey(0, $this->events);

        return $this->events[0];
    }

    public function count(): int
    {
        return count($this->events);
    }
}
