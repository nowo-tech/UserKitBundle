<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

/**
 * House rule LQ (look-alike owners): owner ids with leading / trailing whitespace or control / format characters are
 * refused, owner rows are re-checked with `===`, and MySQL / MariaDB statements add a byte comparison.
 */
final class LookalikeOwnerRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ids(): iterable
    {
        yield 'integer' => ['42', true];
        yield 'uuid' => ['0195f3a2-7b1c-7d2e-8f00-123456789abc', true];
        yield 'e-mail' => ['alice@example.com', true];
        yield 'upper case' => ['Alice', true];
        yield 'accent' => ['álice', true];
        yield 'inner space' => ['Alice Smith', true];
        yield 'max length' => [str_repeat('é', SessionUser::MAX_LENGTH), true];
        yield 'empty' => ['', false];
        yield 'too long' => [str_repeat('x', SessionUser::MAX_LENGTH + 1), false];
        yield 'trailing space' => ['alice ', false];
        yield 'leading space' => [' alice', false];
        yield 'trailing tab' => ["alice\t", false];
        yield 'trailing no-break space' => ["alice\u{00A0}", false];
        yield 'leading ideographic space' => ["\u{3000}alice", false];
        yield 'only spaces' => ['   ', false];
        yield 'zero-width space' => ["ali\u{200B}ce", false];
        yield 'bidi override' => ["\u{202E}alice", false];
        yield 'soft hyphen' => ["al\u{00AD}ice", false];
        yield 'NUL' => ["alice\0", false];
        yield 'newline inside' => ["ali\nce", false];
        yield 'DEL' => ["ali\x7Fce", false];
        yield 'line separator' => ["ali\u{2028}ce", false];
        yield 'invalid UTF-8' => ["ali\xFFce", false];
    }

    #[DataProvider('ids')]
    public function testOwnerIdRule(string $id, bool $usable): void
    {
        self::assertSame($usable, SessionUser::isUsable($id));
        if (!$usable) {
            $this->expectException(InvalidArgumentException::class);
        }
        self::assertSame($id, (new SessionUser('App\\User', $id))->id);
    }

    public function testTheTypeFollowsTheSameRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionUser("App\\User\u{200B}", '7');
    }

    public function testAKeyWithAPaddedIdIsMalformed(): void
    {
        self::assertNull(SessionUser::fromKey('App\\User#alice '));
        self::assertEquals(new SessionUser('App\\User', 'Alice'), SessionUser::fromKey('App\\User#Alice'));
    }

    public function testTheDefaultResolverDoesNotRegisterAPaddedIdentifier(): void
    {
        $resolver = new SessionUserResolver();
        self::assertNull($resolver->resolve(new InMemoryUser('alice ', null)));
        self::assertNull($resolver->resolve(new InMemoryUser("ali\u{200B}ce", null)));
        self::assertEquals(new SessionUser(InMemoryUser::class, 'Alice'), $resolver->resolve(new InMemoryUser('Alice', null)));
    }

    /**
     * @return iterable<string, array{AbstractPlatform, bool}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL 8.0' => [new MySQL80Platform(), true];
        yield 'MySQL 5.7' => [new MySQLPlatform(), true];
        yield 'MariaDB' => [new MariaDBPlatform(), true];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), false];
    }

    #[DataProvider('platforms')]
    public function testTheOwnerPredicateIsByteExactOnMySqlAndMariaDb(AbstractPlatform $platform, bool $binary): void
    {
        self::assertSame(
            'user_type = :type AND user_id = :id' . ($binary ? ' AND CAST(user_type AS BINARY) = CAST(:type AS BINARY) AND CAST(user_id AS BINARY) = CAST(:id AS BINARY)' : ''),
            UserSessionStore::exactOwnerSql($platform),
        );
    }

    public function testRowsAreReCheckedExactly(): void
    {
        $owner = new SessionUser('App\\User', 'alice');
        self::assertTrue(UserSessionStore::isOwner($owner, ['user_type' => 'App\\User', 'user_id' => 'alice']));
        self::assertFalse(UserSessionStore::isOwner($owner, ['user_type' => 'App\\User', 'user_id' => 'Alice']));
        self::assertFalse(UserSessionStore::isOwner($owner, ['user_type' => 'App\\User', 'user_id' => 'alice ']));
        self::assertFalse(UserSessionStore::isOwner($owner, ['user_type' => 'app\\user', 'user_id' => 'alice']));
        self::assertFalse(UserSessionStore::isOwner($owner, ['user_id' => 'alice']));
        self::assertFalse(UserSessionStore::isOwner(new SessionUser('App\\User', '7'), ['user_type' => 'App\\User', 'user_id' => 7]));
    }
}
