<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load;

use Nowo\UserKitBundle\Tests\Load\Support\LoadKernels;
use Nowo\UserKitBundle\Tests\Load\Support\LoadTestCase;
use Nowo\UserKitBundle\Tests\Load\Support\SessionLimitConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_open;
use function rtrim;
use function sort;
use function stream_set_timeout;

use const PHP_BINARY;

/**
 * 1.19.0 (Beacon spec 239), two real processes on MySQL 8.4 / PostgreSQL 16: two browsers of one user sign in at the
 * same moment under a limit of one session. Each registration first asks the device id provider (one second here), so
 * both sign-ins have counted the user's sessions before either registers. Up to 1.18 both counted none and both
 * registered (two live sessions). Under the owner's named lock the second one counts the first: `evict_oldest` signs
 * the first out, `reject_new` refuses the second.
 */
final class SessionLimitRaceTest extends LoadTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function policies(): iterable
    {
        yield 'evict_oldest' => ['evict_oldest'];
        yield 'reject_new' => ['reject_new'];
    }

    #[DataProvider('policies')]
    public function testTwoParallelSignInsKeepOneLiveSession(string $policy): void
    {
        $this->boot(SessionLimitConfig::userKit($policy));
        $this->member('ada@example.com');

        $workers = [$this->worker('a-' . $policy, $policy), $this->worker('b-' . $policy, $policy)];
        foreach ($workers as [, $pipes]) {
            fwrite($pipes[0], "GO\n");
        }
        $answers = [];
        foreach ($workers as [$process, $pipes]) {
            $answers[] = rtrim((string) fgets($pipes[1]));
            proc_close($process);
        }
        sort($answers);

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM user_kit_session WHERE revoked_at IS NULL'), 'one live session: ' . implode(' | ', $answers));
        if ($policy === 'evict_oldest') {
            self::assertSame(['302 /page', '302 /page'], $answers, 'both signed in');
            self::assertSame(1, $this->rows("SELECT COUNT(*) FROM user_kit_session WHERE revoked_reason = 'evicted'"), 'the first was evicted');
        } else {
            self::assertSame(['302 /login', '302 /page'], $answers, 'the second sign-in was refused');
            self::assertSame(1, $this->rows('SELECT COUNT(*) FROM user_kit_session'));
        }
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    private function worker(string $id, string $policy): array
    {
        $env = ['USER_KIT_LOAD_DATABASE_URL' => (string) LoadKernels::url(), 'USER_KIT_LOAD_SLOW_DEVICE_MS' => '1000', 'PATH' => (string) getenv('PATH')];
        // Test-only helper process; every read below is bounded by stream_set_timeout().
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/sign-in-worker.php', $id, $policy], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a sign-in worker.');
        }
        stream_set_timeout($pipes[1], 120);
        $ready = rtrim((string) fgets($pipes[1]));
        if ($ready !== 'READY') {
            throw new RuntimeException('A sign-in worker did not start: ' . $ready);
        }

        return [$process, $pipes];
    }
}
