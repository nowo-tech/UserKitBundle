<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures;

use BackedEnum;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\IdActionLog;
use Throwable;

/**
 * 1.20 AuditKit bridge (audit TC-L5): an action log recorder that fails with `$failure` when it is set, else records
 * nothing (a test switch, not a service of a real host).
 */
final class FailingRecorder implements ActionLogIdRecorderInterface
{
    public ?Throwable $failure = null;

    public int $recorded = 0;

    public function recordIds(string|BackedEnum $action, mixed $actorId = null, mixed $subjectId = null, array $context = [], ?string $scope = null): ActionLogEntryInterface
    {
        return $this->recordIdsAndFlush($action, $actorId, $subjectId, $context, $scope);
    }

    public function recordIdsAndFlush(string|BackedEnum $action, mixed $actorId = null, mixed $subjectId = null, array $context = [], ?string $scope = null): ActionLogEntryInterface
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
        ++$this->recorded;

        return new IdActionLog();
    }
}
