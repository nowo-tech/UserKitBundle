<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use Nowo\StorageKitBundle\Audit\StorageAuditEvent;

/**
 * Collects StorageKit's audit actions of a test.
 */
final class StorageAuditLog
{
    /** @var list<string> */
    public array $actions = [];

    public function __invoke(StorageAuditEvent $event): void
    {
        $this->actions[] = $event->action;
    }
}
