<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\UserKitBundle\Sessions\EventListener\PasswordChangeListener;
use Nowo\UserKitBundle\Sessions\EventListener\UserRemovalListener;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use ReflectionProperty;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;
use WeakReference;

use function spl_object_id;
use function sprintf;

/**
 * REQ-RUNTIME-002 (FrankenPHP worker mode, kernel kept alive and NOT reset): the pending changes of the session
 * registry's Doctrine listeners never outlive their flush, their unit of work or their entity manager.
 *
 * MD-U-M1: before 1.14.2, a password change whose flush failed stayed in `PasswordChangeListener::$pending` and the
 * next flush of any user in that worker signed out every other session of that user.
 * MD-U-L1: before 1.14.2, a removal pending on one entity manager was applied by the flush of another manager that maps
 * the user class too, deleting the sessions of a user that was not removed.
 */
final class SessionListenersWorkerTest extends SessionsKernelTestCase
{
    public function testAFailedPasswordFlushNeverSignsTheUserOutOnAnotherUsersFlush(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('bob@example.com');
        self::assertCount(2, $this->rowsOf($alice));

        // Alice's password change: preUpdate runs, the UPDATE is aborted, postFlush never runs.
        $connection = $this->em()->getConnection();
        $connection->executeStatement("CREATE TRIGGER worker_test_abort BEFORE UPDATE ON user_kit_test_member WHEN NEW.email = 'alice@example.com' BEGIN SELECT RAISE(ABORT, 'worker test: forced flush failure'); END");
        $member = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $member->setPassword('REDACTED-TEST-a');
        try {
            $this->em()->flush();
            self::fail('The flush must fail.');
        } catch (Throwable $e) {
            self::assertStringContainsString('forced flush failure', $e->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER worker_test_abort');
        }
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        if (!$this->em()->isOpen()) {
            $doctrine->resetManager();
        }
        self::assertSame([], $this->pending(PasswordChangeListener::class));

        // Later, same kernel and no reset: bob changes his password. Only bob is signed out.
        $member = $this->em()->find(Member::class, $bob->getId()) ?? self::fail();
        $member->setPassword('REDACTED-TEST-b');
        $this->em()->flush();
        self::assertCount(0, $this->rowsOf($bob));
        self::assertCount(2, $this->rowsOf($alice), 'Alice keeps her sessions: her change was never stored.');
    }

    public function testPasswordChangesOfAnEarlierUnitOfWorkAndEveryBoundaryAreDropped(): void
    {
        $this->boot();
        $alice    = $this->member('alice@example.com');
        $listener = $this->container()->get(PasswordChangeListener::class);
        self::assertInstanceOf(PasswordChangeListener::class, $listener);
        $em     = $this->em();
        $change = ['password' => ['REDACTED-TEST-old', 'REDACTED-TEST-new']];

        // A nested flush of the same unit of work keeps the outer change.
        $listener->preUpdate($alice, new PreUpdateEventArgs($alice, $em, $change));
        $listener->onFlush(new OnFlushEventArgs($em));
        self::assertCount(1, $this->pending(PasswordChangeListener::class));

        // A change collected by a unit of work the manager no longer has: dropped by the next flush, replaced by preUpdate.
        $this->stale(PasswordChangeListener::class, 'changes', $alice);
        $listener->onFlush(new OnFlushEventArgs($em));
        self::assertSame([], $this->pending(PasswordChangeListener::class));
        $this->stale(PasswordChangeListener::class, 'changes', $alice);
        $listener->preUpdate($alice, new PreUpdateEventArgs($alice, $em, $change));
        self::assertSame($em->getUnitOfWork(), $this->pending(PasswordChangeListener::class)[spl_object_id($em)]['uow']->get());

        $listener->onClear(new OnClearEventArgs($em));
        self::assertSame([], $this->pending(PasswordChangeListener::class));

        $listener->preUpdate($alice, new PreUpdateEventArgs($alice, $em, $change));
        $listener->onRequest(new RequestEvent($this->http(), Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertCount(1, $this->pending(PasswordChangeListener::class));
        $listener->onRequest(new RequestEvent($this->http(), Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([], $this->pending(PasswordChangeListener::class));
    }

    public function testARemovalPendingOnOneManagerIsNeverAppliedByAnotherOne(): void
    {
        $this->boot(['user_removal' => ['delete_sessions' => true]]);
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $listener = $this->container()->get(UserRemovalListener::class);
        self::assertInstanceOf(UserRemovalListener::class, $listener);
        $em = $this->em();
        // A second manager on the same mapping (e.g. a host's read or reporting manager mapping the user class too).
        $other = new EntityManager($em->getConnection(), $em->getConfiguration());

        $listener->preRemove($alice, new PreRemoveEventArgs($alice, $em));
        $listener->postFlush(new PostFlushEventArgs($other));
        self::assertCount(1, $this->pending(UserRemovalListener::class));
        self::assertCount(1, $this->rowsOf($alice), 'Alice was not removed: her sessions stay.');

        // A unit of work the manager no longer has: dropped, never applied.
        $this->stale(UserRemovalListener::class, 'removals', $alice);
        $listener->postFlush(new PostFlushEventArgs($em));
        self::assertSame([], $this->pending(UserRemovalListener::class));
        self::assertCount(1, $this->rowsOf($alice));

        $listener->preRemove($alice, new PreRemoveEventArgs($alice, $em));
        $listener->onClear(new OnClearEventArgs($other));
        self::assertCount(1, $this->pending(UserRemovalListener::class), 'Clearing another manager keeps it.');
        $listener->onClear(new OnClearEventArgs($em));
        self::assertSame([], $this->pending(UserRemovalListener::class));

        $listener->preRemove($alice, new PreRemoveEventArgs($alice, $em));
        $listener->onRequest(new RequestEvent($this->http(), Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertCount(1, $this->pending(UserRemovalListener::class));
        $listener->onRequest(new RequestEvent($this->http(), Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([], $this->pending(UserRemovalListener::class));

        // Called without a manager (BC): drops everything.
        $listener->preRemove($alice, new PreRemoveEventArgs($alice, $em));
        $listener->onClear();
        self::assertSame([], $this->pending(UserRemovalListener::class));
    }

    /**
     * @param class-string $class
     *
     * @phpstan-impure
     *
     * @return array<int, array{uow: WeakReference<object>}>
     */
    private function pending(string $class): array
    {
        $pending = (new ReflectionProperty($class, 'pending'))->getValue($this->container()->get($class));
        self::assertIsArray($pending);

        /* @var array<int, array{uow: WeakReference<object>}> $pending */
        return $pending;
    }

    /**
     * Puts an entry of a unit of work the default manager no longer has (as after a failed flush and a reset).
     *
     * @param class-string $class
     */
    private function stale(string $class, string $list, Member $user): void
    {
        $gone = new stdClass();
        $item = $list === 'changes'
            ? ['user' => $user, 'except' => null]
            : ['user' => $user, 'owner' => new SessionUser(Member::class, sprintf('%d', $user->getId()))];
        (new ReflectionProperty($class, 'pending'))->setValue($this->container()->get($class), [
            spl_object_id($this->em()) => ['uow' => WeakReference::create($gone), $list => [spl_object_id($user) => $item]],
        ]);
    }

    private function http(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
