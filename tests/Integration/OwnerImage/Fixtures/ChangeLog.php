<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;

/**
 * Collects the owner image events of a test.
 */
final class ChangeLog
{
    /** @var list<OwnerImageChangedEvent> */
    public array $events = [];

    public function __invoke(OwnerImageChangedEvent $event): void
    {
        $this->events[] = $event;
    }
}
