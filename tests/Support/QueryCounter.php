<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function count;
use function is_string;
use function str_contains;

/**
 * Counts the SQL statements a test request executes: the logger of `Doctrine\DBAL\Logging\Middleware`, which logs one
 * message with an `sql` context entry per executed query or statement (transactions and connections carry none).
 */
final class QueryCounter extends AbstractLogger
{
    /** @var list<string> executed SQL, in order */
    public array $queries = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;
        if (is_string($sql)) {
            $this->queries[] = $sql;
        }
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    /**
     * How many of the executed statements mention `$table` (the registry queries of a request, without the host's own).
     */
    public function on(string $table): int
    {
        return count(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, $table)));
    }
}
