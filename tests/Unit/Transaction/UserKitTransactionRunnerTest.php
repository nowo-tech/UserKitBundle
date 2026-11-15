<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\Note;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\RecordingLogger;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\RunnerDatabase;
use Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures\Tag;
use Nowo\UserKitBundle\Transaction\ClosedManagerRecovery;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function bin2hex;
use function random_bytes;

final class UserKitTransactionRunnerTest extends TestCase
{
    private EntityManager $em;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->em     = RunnerDatabase::entityManager();
        $this->logger = new RecordingLogger();
    }

    public function testAUnitIsFlushedOnceAndCommitted(): void
    {
        $note = $this->runner()->runFor($this->em, function (): Note {
            $note = new Note('one');
            $this->em->persist($note);

            return $note;
        });

        self::assertNotNull($note->id);
        self::assertTrue($this->em->contains($note));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame(1, $this->rowCount('runner_note'));
    }

    public function testRunUsesTheDefaultManager(): void
    {
        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, $this->runner()->run(static fn (): string => $expected));
    }

    public function testRunRefusesADefaultManagerThatIsNotAnOrmOne(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        $this->expectException(LogicException::class);
        (new UserKitTransactionRunner($doctrine))->run(static fn (): null => null);
    }

    public function testADomainFailureDetachesOnlyWhatTheUnitAddedOrChanged(): void
    {
        [$kept, $changed] = $this->notes('kept', 'changed');
        // The caller's own pending change, made before the unit: it stays pending.
        $kept->title = 'caller';
        $added       = new Note('added');

        $failure = $this->failing(function () use ($changed, $added): never {
            $changed->title = 'unit';
            $this->em->persist($added);

            throw new RuntimeException('refused');
        });

        self::assertSame('refused', $failure->getMessage());
        self::assertTrue($this->em->contains($kept), 'the caller row stays managed');
        self::assertFalse($this->em->contains($changed), 'the row the unit changed is detached');
        self::assertFalse($this->em->contains($added), 'the row the unit added is detached');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $this->em->flush();
        self::assertSame('caller', $this->em->getConnection()->fetchOne('SELECT title FROM runner_note WHERE id = ?', [$kept->id]), 'the caller change is written by its own flush');
        self::assertSame(2, $this->rowCount('runner_note'));
    }

    public function testAFailureAfterAFlushInsideTheUnitDetachesTheFlushedRows(): void
    {
        [$kept, $flushed] = $this->notes('kept', 'flushed');

        $this->failing(function () use ($flushed): never {
            $flushed->title = 'unit';
            $this->em->flush();

            throw new RuntimeException('after the flush');
        });

        self::assertTrue($this->em->contains($kept));
        self::assertFalse($this->em->contains($flushed), 'its flushed state was rolled back');
        self::assertSame('flushed', $this->em->getConnection()->fetchOne('SELECT title FROM runner_note WHERE id = ?', [$flushed->id]));
    }

    public function testALazyRowTheUnitOnlyLoadedStaysManagedAndOneItChangedIsDetached(): void
    {
        [$a, $b] = $this->notes('a', 'b');
        $ids     = [$a->id, $b->id];
        $this->em->clear();
        $loaded  = $this->em->getReference(Note::class, $ids[0]) ?? self::fail('a');
        $changed = $this->em->getReference(Note::class, $ids[1]) ?? self::fail('b');

        $this->failing(static function () use ($loaded, $changed): never {
            self::assertSame('a', $loaded->title);
            $changed->title = 'unit';

            throw new RuntimeException('refused');
        });

        self::assertTrue($this->em->contains($loaded));
        self::assertFalse($this->em->contains($changed));
    }

    public function testALazyRowTheUnitDidNotTouchStaysLazy(): void
    {
        [$a] = $this->notes('a');
        $id  = $a->id;
        $this->em->clear();
        $lazy = $this->em->getReference(Note::class, $id) ?? self::fail('a');

        $this->failing(static function (): never {
            throw new RuntimeException('refused');
        });

        self::assertTrue($this->em->contains($lazy));
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($lazy));
    }

    public function testARowPointingAtADetachedRowGoesTooAndIsLogged(): void
    {
        [$note] = $this->notes('note');
        $tag    = new Tag('tag', $note);
        $other  = new Tag('other');
        $this->em->persist($tag);
        $this->em->persist($other);
        $this->em->flush();
        $this->em->clear();
        $tag   = $this->em->find(Tag::class, $tag->id) ?? self::fail('tag');
        $other = $this->em->find(Tag::class, $other->id) ?? self::fail('other');
        $note  = $tag->note ?? self::fail('note');
        self::assertSame('note', $note->title);

        $this->failing(static function () use ($note): never {
            $note->title = 'unit';

            throw new RuntimeException('refused');
        });

        self::assertFalse($this->em->contains($note));
        self::assertFalse($this->em->contains($tag), 'it points at the detached note');
        self::assertTrue($this->em->contains($other));
        self::assertCount(1, $this->logger->messages);
    }

    /**
     * Audit QM-M1: an eraser's `remove()` of a failed unit is cancelled, so the caller's next flush does not delete the
     * row (up to this fix the runner left it scheduled; 1.18's `wrapInTransaction()` closed the manager).
     */
    public function testARemovalOfAFailedUnitIsCancelled(): void
    {
        [$address, $hosts] = $this->notes('address', 'host');
        $this->em->remove($hosts);

        $this->failing(function () use ($address): never {
            $this->em->remove($address);

            throw new RuntimeException('eraser 2 refused');
        });

        self::assertTrue($this->em->contains($address));
        self::assertFalse($this->em->getUnitOfWork()->isScheduledForDelete($address));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForDelete($hosts), 'the caller\'s own removal stays scheduled');
        $this->em->persist(new Note('unrelated'));
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM runner_note WHERE title = 'address'"));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM runner_note WHERE title = 'host'"));
    }

    /**
     * Audit QM-M1: a collection the failed unit changed cannot be reverted row by row: the manager is closed (loud) and
     * replaced at the next boundary.
     */
    public function testACollectionChangeOfAFailedUnitClosesTheManager(): void
    {
        [$note] = $this->notes('note');
        $tag    = new Tag('label');
        $this->em->persist($tag);
        $this->em->flush();
        $doctrine = $this->registry();
        $doctrine->expects($this->once())->method('resetManager')->with('default');
        $recovery = new ClosedManagerRecovery($doctrine);

        $this->failing(static function () use ($note, $tag): never {
            $note->labels->add($tag);

            throw new RuntimeException('refused');
        }, new UserKitTransactionRunner($doctrine, $recovery));

        self::assertFalse($this->em->isOpen());
        $recovery->reset();
    }

    public function testACollectionReplacedByAFailedUnitClosesTheManager(): void
    {
        [$note] = $this->notes('note');

        $this->failing(static function () use ($note): never {
            $note->labels = new ArrayCollection();

            throw new RuntimeException('refused');
        });

        self::assertFalse($this->em->isOpen());
    }

    public function testACollectionTheCallerChangedBeforeTheUnitKeepsTheManagerOpen(): void
    {
        [$note] = $this->notes('note');
        $tag    = new Tag('label');
        $this->em->persist($tag);
        $this->em->flush();
        $note->labels->add($tag);

        $this->failing(static function (): never {
            throw new RuntimeException('refused');
        });

        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($note));
    }

    public function testATransientFailureBecomesAUserKitStorageFailure(): void
    {
        $deadlock = new DeadlockException(new class('deadlock', null, 1213) extends AbstractException {
        }, null);

        $failure = $this->failing(static function () use ($deadlock): never {
            throw $deadlock;
        });

        self::assertInstanceOf(UserKitStorageFailure::class, $failure);
        self::assertSame($deadlock, $failure->getPrevious());
        self::assertSame(1, $failure->retryAfterSeconds());
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage());
    }

    public function testATransientFailureOfAnotherRunnerBecomesAUserKitStorageFailure(): void
    {
        $cause = new RuntimeException('storage');

        $failure = $this->failing(static function () use ($cause): never {
            throw new TransientStorageFailure(5, previous: $cause);
        });
        self::assertInstanceOf(UserKitStorageFailure::class, $failure);
        self::assertSame($cause, $failure->getPrevious());

        $bare = $this->failing(static function (): never {
            throw new TransientStorageFailure();
        });
        self::assertInstanceOf(UserKitStorageFailure::class, $bare);
        self::assertInstanceOf(TransientStorageFailure::class, $bare->getPrevious());
    }

    public function testUserKitFailuresPassUnchanged(): void
    {
        $storage  = new UserKitStorageFailure(3);
        $conflict = new UserKitConflict();

        self::assertSame($storage, $this->failing(static function () use ($storage): never {
            throw $storage;
        }));
        self::assertSame($conflict, $this->failing(static function () use ($conflict): never {
            throw $conflict;
        }));
    }

    public function testALostUniqueKeyRaceIsAConflict(): void
    {
        $this->notes('taken');

        $failure = $this->failing(function (): void {
            $this->em->getConnection()->insert('runner_note', ['title' => 'taken']);
        });

        self::assertInstanceOf(UserKitConflict::class, $failure);
        self::assertInstanceOf(UniqueConstraintViolationException::class, $failure->getPrevious());
        self::assertSame(UserKitConflict::DEFAULT_MESSAGE, $failure->getMessage());
    }

    public function testAManagerTheFailedFlushClosedStaysClosedUntilTheBoundary(): void
    {
        $this->notes('taken');
        $doctrine = $this->registry();
        $doctrine->expects($this->once())->method('resetManager')->with('default');
        $recovery = new ClosedManagerRecovery($doctrine);

        $failure = $this->failing(function (): void {
            $this->em->persist(new Note('taken'));
        }, new UserKitTransactionRunner($doctrine, $recovery));

        self::assertInstanceOf(UserKitConflict::class, $failure);
        self::assertFalse($this->em->isOpen(), 'loud for the rest of the request');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $recovery->reset();
        $recovery->reset();
    }

    public function testASingleStatementIsRunOnce(): void
    {
        $calls    = 0;
        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, $this->runner()->statement($this->em->getConnection(), static function () use (&$calls, $expected): string {
            ++$calls;

            return $expected;
        }));
        self::assertSame(1, $calls);
    }

    public function testATransientStatementIsRetriedInAutocommit(): void
    {
        $calls    = 0;
        $expected = bin2hex(random_bytes(4));
        self::assertSame($expected, $this->runner()->statement($this->em->getConnection(), function () use (&$calls, $expected): string {
            if (++$calls < 3) {
                throw $this->deadlock();
            }

            return $expected;
        }));
        self::assertSame(3, $calls);
    }

    public function testATransientStatementGivesUpAfterTheLastAttempt(): void
    {
        $calls = 0;
        try {
            $this->runner()->statement($this->em->getConnection(), function () use (&$calls): never {
                ++$calls;

                throw $this->deadlock();
            });
        } catch (UserKitStorageFailure $failure) {
            self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
        }
        self::assertSame(UserKitTransactionRunner::STATEMENT_ATTEMPTS, $calls);
    }

    public function testATransientStatementInsideATransactionIsNeverReplayed(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $calls = 0;
        try {
            $this->runner()->statement($connection, function () use (&$calls): never {
                ++$calls;

                throw $this->deadlock();
            });
        } catch (UserKitStorageFailure) {
            self::assertSame(1, $calls);
        }
        try {
            $connection->rollBack();
        } catch (Throwable) {
            $connection->close();
        }
    }

    public function testOtherStatementFailuresAreRethrown(): void
    {
        $refused = new RuntimeException('refused');
        $storage = new UserKitStorageFailure();
        foreach ([$refused, $storage] as $expected) {
            try {
                $this->runner()->statement($this->em->getConnection(), static function () use ($expected): never {
                    throw $expected;
                });
            } catch (Throwable $failure) {
                self::assertSame($expected, $failure);
            }
        }
    }

    private function runner(): UserKitTransactionRunner
    {

        return new UserKitTransactionRunner($this->registry(), null, 1, UserKitTransactionRunner::STATEMENT_ATTEMPTS, 0, $this->logger);
    }

    /**
     * @return ManagerRegistry&MockObject
     */
    private function registry(): ManagerRegistry
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->em);
        $doctrine->method('getManagers')->willReturn(['default' => $this->em]);

        return $doctrine;
    }

    private function failing(callable $operation, ?UserKitTransactionRunner $runner = null): Throwable
    {
        try {
            ($runner ?? $this->runner())->runFor($this->em, $operation);
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The unit should have failed.');
    }

    /**
     * @return list<Note>
     */
    private function notes(string ...$titles): array
    {
        $notes = [];
        foreach ($titles as $title) {
            $notes[] = $note = new Note($title);
            $this->em->persist($note);
        }
        $this->em->flush();

        return $notes;
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('deadlock', null, 1213) extends AbstractException {
        }, null);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
