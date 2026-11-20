<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\PersonalData;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\PersonalData\ErasureGuardInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\ErasureVeto;
use Nowo\UserKitBundle\PersonalData\ErasureVetoedException;
use Nowo\UserKitBundle\PersonalData\Event\AccountErasedEvent;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

final class PersonalDataEraserTest extends TestCase
{
    public function testUnmanagedUserClassIsRejected(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        (new PersonalDataEraser([], [], $doctrine))->erase(new stdClass());
    }

    public function testUnsupportedErasersAreSkipped(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($entityManager));
        $entityManager->expects($this->once())->method('flush');
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);

        $eraser = static fn (string $key, bool $supports): PersonalDataEraserInterface => new class($key, $supports) implements PersonalDataEraserInterface {
            public function __construct(private readonly string $key, private readonly bool $supports)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function supports(object $user): bool
            {
                return $this->supports;
            }

            public function erase(ErasureRequest $request): void
            {
            }
        };

        $this->assertSame(['a', 'c'], (new PersonalDataEraser([$eraser('a', true), $eraser('b', false), $eraser('c', true)], [], $doctrine))->erase(new stdClass()));
    }

    public function testAnOpenHostTransactionIsJoinedNotOwned(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects($this->never())->method('wrapInTransaction');
        $entityManager->expects($this->once())->method('flush');
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);
        $eraser = $this->createMock(PersonalDataEraserInterface::class);
        $eraser->method('supports')->willReturn(true);
        $eraser->method('getKey')->willReturn('host');
        $eraser->expects($this->once())->method('erase');

        self::assertSame(['host'], (new PersonalDataEraser([$eraser], [], $doctrine))->erase(new stdClass()));
    }

    public function testAFailingEraserMarksTheJoinedHostTransactionRollbackOnly(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('setRollbackOnly');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects($this->never())->method('flush');
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);
        $eraser = $this->createMock(PersonalDataEraserInterface::class);
        $eraser->method('supports')->willReturn(true);
        $eraser->method('erase')->willThrowException(new RuntimeException('boom'));

        $this->expectExceptionObject(new RuntimeException('boom'));
        (new PersonalDataEraser([$eraser], [], $doctrine))->erase(new stdClass());
    }

    public function testErasersSeeTheIdentifierCapturedBeforeAnyOfThemRan(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($entityManager));
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);

        $user = new class implements UserInterface {
            /** @var non-empty-string */
            public string $email = 'alice@example.com';

            public function getUserIdentifier(): string
            {
                return $this->email;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };
        $seen     = new Recorder();
        $identity = new class implements PersonalDataEraserInterface {
            public function getKey(): string
            {
                return 'identity';
            }

            public function supports(object $user): bool
            {
                return true;
            }

            public function erase(ErasureRequest $request): void
            {
                assert(property_exists($request->user, 'email'));
                $request->user->email = 'erased-1@invalid.local';
            }
        };
        $later = new class($seen) implements PersonalDataEraserInterface {
            public function __construct(private readonly Recorder $seen)
            {
            }

            public function getKey(): string
            {
                return 'later';
            }

            public function supports(object $user): bool
            {
                return true;
            }

            public function erase(ErasureRequest $request): void
            {
                $this->seen->items[] = [$request->originalIdentifier, $request->identifier()];
            }
        };

        self::assertSame(['identity', 'later'], (new PersonalDataEraser([$identity, $later], [], $doctrine))->erase($user));
        self::assertSame([['alice@example.com', 'alice@example.com']], $seen->items);
        self::assertSame('erased-1@invalid.local', $user->getUserIdentifier());
    }

    public function testRequestIdentifierFallsBack(): void
    {
        self::assertSame('bob', (new ErasureRequest(new InMemoryUser('bob', null)))->identifier());
        self::assertSame('old', (new ErasureRequest(new InMemoryUser('bob', null), originalIdentifier: 'old'))->identifier());
        self::assertNull((new ErasureRequest(new stdClass()))->identifier());
    }

    public function testCheckSeesTheIdentifier(): void
    {
        $seen  = new Recorder();
        $guard = new class($seen) implements ErasureGuardInterface {
            public function __construct(private readonly Recorder $seen)
            {
            }

            public function check(ErasureRequest $request): ?ErasureVeto
            {
                $this->seen->items[] = $request->originalIdentifier;

                return null;
            }
        };

        self::assertSame([], (new PersonalDataEraser([], [$guard], $this->createMock(ManagerRegistry::class)))->check(new InMemoryUser('carol', null)));
        self::assertSame(['carol'], $seen->items);
    }

    public function testAllVetoesAreCollected(): void
    {
        $guard = static fn (?ErasureVeto $veto): ErasureGuardInterface => new class($veto) implements ErasureGuardInterface {
            public function __construct(private readonly ?ErasureVeto $veto)
            {
            }

            public function check(ErasureRequest $request): ?ErasureVeto
            {
                return $this->veto;
            }
        };

        $eraser = new PersonalDataEraser([], [
            $guard(new ErasureVeto('sole_owner', '', ['projects' => ['p1']])),
            $guard(null),
            $guard(new ErasureVeto('last_admin')),
        ], $this->createMock(ManagerRegistry::class));

        $this->assertCount(2, $eraser->check(new stdClass()));

        try {
            $eraser->erase(new stdClass());
            $this->fail('Expected a veto.');
        } catch (ErasureVetoedException $exception) {
            $this->assertSame(['sole_owner', 'last_admin'], $exception->getReasons());
            $this->assertFalse($exception->hasReason('other'));
            $this->assertSame('Personal data erasure vetoed: sole_owner, last_admin.', $exception->getMessage());
            $this->assertSame(['projects' => ['p1']], $exception->vetoes[0]->details);
        }
    }

    public function testThePsrDispatcherGetsTheEventAfterTheCommitAndItsFailureIsLogged(): void
    {
        $doctrine   = $this->doctrineWithoutTransaction();
        $at         = new DateTimeImmutable('2026-11-20 10:00:00');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $event) use ($at): never {
            self::assertInstanceOf(AccountErasedEvent::class, $event);
            self::assertSame($at, $event->erasedAt);
            self::assertSame(['host'], $event->erasers);
            self::assertNull($event->userType);
            self::assertNull($event->userId);
            self::assertSame(['tenant' => 't1'], $event->context);
            self::assertFalse($event->selfService);

            throw new RuntimeException('listener failed');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(self::stringContains('AccountErasedEvent'));

        $eraser = new PersonalDataEraser([$this->hostEraser()], [], $doctrine, null, null, $dispatcher, $logger, new MockClock($at));

        self::assertSame(['host'], $eraser->erase(new stdClass(), new stdClass(), ['tenant' => 't1']));
    }

    public function testEverySymfonyListenerRunsWhenOneThrows(): void
    {
        $dispatcher = new EventDispatcher();
        $seen       = new Recorder();
        $dispatcher->addListener(AccountErasedEvent::class, static function (): never {
            throw new RuntimeException('first failed');
        }, 10);
        $dispatcher->addListener(AccountErasedEvent::class, static function (AccountErasedEvent $event, string $name) use ($seen): void {
            $seen->items[] = $name;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new PersonalDataEraser([$this->hostEraser()], [], $this->doctrineWithoutTransaction(), null, null, $dispatcher, $logger))->erase(new stdClass());

        self::assertSame([AccountErasedEvent::class], $seen->items);
    }

    public function testAnOpenTransactionUserKitCannotFollowIsRefusedBeforeAnythingIsErased(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);
        $eraser = $this->createMock(PersonalDataEraserInterface::class);
        $eraser->expects($this->never())->method('erase');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('AccountErasedEvent');
        (new PersonalDataEraser([$eraser], [], $doctrine, null, null, new EventDispatcher()))->erase(new stdClass());
    }

    public function testRequestSelfService(): void
    {
        $user  = new stdClass();
        $admin = new stdClass();

        $this->assertTrue((new ErasureRequest($user))->isSelfService());
        $this->assertTrue((new ErasureRequest($user, $user))->isSelfService());
        $this->assertFalse((new ErasureRequest($user, $admin, ['scope' => 'admin']))->isSelfService());
    }

    private function doctrineWithoutTransaction(): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);

        return $doctrine;
    }

    private function hostEraser(): PersonalDataEraserInterface
    {
        $eraser = $this->createMock(PersonalDataEraserInterface::class);
        $eraser->method('supports')->willReturn(true);
        $eraser->method('getKey')->willReturn('host');

        return $eraser;
    }
}

/** Collects what the test erasers saw. */
final class Recorder
{
    /** @var list<mixed> */
    public array $items = [];
}
