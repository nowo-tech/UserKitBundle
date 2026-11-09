<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The touch fallback is a locking read on MySQL/MariaDB only (IY-L1).
 */
final class UserSessionStoreLockTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL 8.0' => [new MySQL80Platform(), ' FOR SHARE'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), ' LOCK IN SHARE MODE'];
        yield 'MariaDB' => [new MariaDBPlatform(), ' LOCK IN SHARE MODE'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), ''];
    }

    #[DataProvider('platforms')]
    public function testTheFallbackLocksOnMySqlAndMariaDbOnly(AbstractPlatform $platform, string $clause): void
    {
        self::assertSame($clause, UserSessionStore::sharedLock($platform));
    }
}
