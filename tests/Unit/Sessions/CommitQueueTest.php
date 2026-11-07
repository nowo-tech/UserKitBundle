<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Sessions\Transaction\TransactionBoundaryMiddleware;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The commit hook itself, on a real connection: DBAL reaches the driver for the outermost transaction only.
 */
final class CommitQueueTest extends TestCase
{
    private Connection $connection;

    private CommitQueue $queue;

    private int $ran = 0;

    protected function setUp(): void
    {
        $registry         = $this->createStub(ManagerRegistry::class);
        $this->queue      = new CommitQueue($registry);
        $this->connection = $this->connect($this->queue, 'main');
        $registry->method('getConnectionNames')->willReturn(['other' => 'other.service', 'main' => 'main.service']);
        $unrelated = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry->method('getConnection')->willReturnMap([['other', $unrelated], ['main', $this->connection]]);
    }

    private function connect(CommitQueue $queue, string $name): Connection
    {
        $middleware = new TransactionBoundaryMiddleware($queue);
        $middleware->setConnectionName($name);
        $middleware->reset();

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([$middleware]));
    }

    private function hold(?Connection $connection = null): bool
    {
        return $this->queue->hold($connection ?? $this->connection, function (): void {
            ++$this->ran;
        });
    }

    public function testNothingIsHeldWithoutATransaction(): void
    {
        self::assertFalse($this->hold());
    }

    public function testACommitReleasesAtTheNextBoundaryNotBefore(): void
    {
        $this->connection->beginTransaction();
        self::assertTrue($this->hold());
        $this->queue->release();
        self::assertSame(0, $this->ran, 'The transaction is open.');
        $this->connection->commit();
        self::assertSame(0, $this->ran);
        $this->queue->release();
        self::assertSame(1, $this->ran);
        $this->queue->release();
        self::assertSame(1, $this->ran, 'Once.');
    }

    public function testARollbackDrops(): void
    {
        $this->connection->beginTransaction();
        $this->hold();
        $this->connection->rollBack();
        $this->queue->release();
        self::assertSame(0, $this->ran);
        self::assertFalse($this->hold(), 'No transaction after it.');
    }

    public function testARollbackReachesTheQueueItself(): void
    {
        $this->connection->beginTransaction();
        $this->hold();
        $this->connection->rollBack();
        $this->queue->committed('main');
        $this->queue->release();
        self::assertSame(0, $this->ran);
    }

    public function testATransactionThatEndedUnseenLeavesNothingForTheNextOne(): void
    {
        $this->connection->beginTransaction();
        $this->hold();
        $this->connection->close();
        $this->connection->beginTransaction();
        $this->connection->commit();
        $this->queue->release();
        self::assertSame(0, $this->ran);
    }

    public function testASavepointIsNotTheOutermostCommit(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->hold();
        $this->connection->commit();
        $this->queue->release();
        self::assertSame(0, $this->ran);
        $this->connection->commit();
        $this->queue->release();
        self::assertSame(1, $this->ran);
    }

    public function testAConnectionTheQueueWasNeverToldAboutIsNotHeld(): void
    {
        $watchedElsewhere = $this->connect(new CommitQueue($this->createStub(ManagerRegistry::class)), 'main');
        $watchedElsewhere->beginTransaction();
        self::assertFalse($this->hold($watchedElsewhere), 'Not in the registry.');
        $watchedElsewhere->rollBack();

        $unwatched = new CommitQueue($this->registryOf('main', $bare = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])));
        $bare->beginTransaction();
        self::assertFalse($unwatched->hold($bare, static function (): void {}), 'No middleware on it: no signal would come.');
        $bare->rollBack();
    }

    public function testAFailedDriverCommitDropsAndGoesOn(): void
    {
        $registry   = $this->createStub(ManagerRegistry::class);
        $queue      = new CommitQueue($registry);
        $middleware = new TransactionBoundaryMiddleware($queue);
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            // The failing layer sits below ours, as a database refusing COMMIT would.
            (new Configuration())->setMiddlewares([$this->failingCommits(), $middleware]),
        );
        $registry->method('getConnectionNames')->willReturn(['default' => 'default.service']);
        $registry->method('getConnection')->willReturn($connection);
        $connection->beginTransaction();
        self::assertTrue($queue->hold($connection, function (): void {
            ++$this->ran;
        }));

        try {
            $connection->commit();
            self::fail('The commit fails.');
        } catch (Throwable) {
            // Expected.
        }
        $queue->release();
        self::assertSame(0, $this->ran);
    }

    private function registryOf(string $name, Connection $connection): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnectionNames')->willReturn([$name => $name . '.service']);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }

    private function failingCommits(): Driver\Middleware
    {
        return new class implements Driver\Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function commit(): void
                            {
                                throw new class('COMMIT refused') extends Exception implements DriverException {
                                    public function getSQLState(): ?string
                                    {
                                        return null;
                                    }
                                };
                            }
                        };
                    }
                };
            }
        };
    }
}
