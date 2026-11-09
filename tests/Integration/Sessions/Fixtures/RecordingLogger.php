<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * What was logged so far, then forgotten.
     *
     * @phpstan-impure
     *
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function take(): array
    {
        $records       = $this->records;
        $this->records = [];

        return $records;
    }
}
