<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\EventSubscriber\UnitOfWorkBoundarySubscriber;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionsTestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function assert;
use function ltrim;
use function md5;
use function parse_url;
use function preg_match;
use function serialize;
use function sprintf;
use function str_starts_with;

use const PHP_URL_PATH;

/**
 * 1.19.0 (Beacon spec 239): the real-database load tests, on the server of `USER_KIT_LOAD_DATABASE_URL` (MySQL 8.4 or
 * PostgreSQL 16 in CI, DBAL 4 and DBAL 3). The test's own connection is UserKit's (a booted session registry test
 * kernel); a second session in another process holds locks against it. Use a throwaway database: the setup drops
 * everything in it and refuses a name with no `test`, `race`, `load` or `throwaway` part. Without the variable the
 * tests are skipped.
 */
abstract class LoadTestCase extends TestCase
{
    protected SessionsTestKernel $kernel;

    protected ?SecondConnection $other = null;

    /**
     * @param array<string, mixed> $userKit `nowo_user_kit` configuration
     */
    protected function boot(array $userKit): void
    {
        $url = LoadKernels::url();
        if ($url === null) {
            self::markTestSkipped('Set USER_KIT_LOAD_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|load|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the setup drops everything in it.', $database));
        }
        $this->kernel = LoadKernels::kernel(md5(serialize([$userKit, static::class])), $userKit);
        $this->kernel->boot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->connection()->executeStatement('CREATE TABLE load_ballast (id INT NOT NULL PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    protected function mysql(): bool
    {
        return str_starts_with((string) LoadKernels::url(), 'mysql');
    }

    protected function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function get(string $class): object
    {
        $service = $this->container()->get($class);
        assert($service instanceof $class);

        return $service;
    }

    protected function em(): EntityManagerInterface
    {
        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function otherSession(): SecondConnection
    {
        return $this->other ??= new SecondConnection((string) LoadKernels::url());
    }

    protected function member(string $email): Member
    {
        $member = new Member($email, 'secret');
        $this->em()->persist($member);
        $this->em()->flush();

        return $member;
    }

    /**
     * The test's connection gives up waiting for a row lock after about a second (lock wait timeout 1205 on MySQL,
     * lock_not_available 55P03 on PostgreSQL): both are transient failures.
     */
    protected function shortLockWait(): void
    {
        $this->connection()->executeStatement($this->mysql() ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
    }

    protected function sleepStatement(float $seconds): string
    {
        return $this->mysql() ? sprintf('SELECT SLEEP(%F)', $seconds) : sprintf('SELECT pg_sleep(%F)', $seconds);
    }

    /**
     * Makes the second session the heavier transaction, so a MySQL deadlock picks the test's connection as the victim.
     */
    protected function ballast(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame('OK', $this->otherSession()->execute('INSERT INTO load_ballast (id) VALUES (' . $i . ')'));
        }
    }

    /**
     * What the worker's next request finds after a failure, once the unit-of-work boundary ran (UserKit replaces a
     * manager a failed write closed there, never mid-request): no transaction left open, the manager open.
     */
    protected function assertHealthy(): void
    {
        $this->get(UnitOfWorkBoundarySubscriber::class)->onBoundary();
        self::assertSame(0, $this->connection()->getTransactionNestingLevel(), 'no transaction left open');
        self::assertTrue($this->em()->isOpen(), 'the entity manager is open');
    }

    /**
     * @param class-string<Throwable> $class
     */
    protected function causedBy(Throwable $failure, string $class): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }

    protected function rows(string $sql): int
    {
        return (int) $this->connection()->fetchOne($sql);
    }
}
