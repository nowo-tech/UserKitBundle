<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps the messages it was given.
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
