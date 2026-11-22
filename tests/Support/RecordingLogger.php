<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps the messages logged during a test.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
