<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use Nowo\UserKitBundle\Sessions\Owner\UnresolvedSessionOwnerException;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\Ui\SessionRow;
use Nowo\UserKitBundle\Sessions\UserSessionsInterface;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\MemberOwnerResolver;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\NoOwnerResolver;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\RecordingLogger;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SecurityMember;
use stdClass;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_map;

/**
 * DDD ports: `sessions.owner.resolver` (one owner for the security user and the entity), `sessions.owner.on_unresolved`
 * (revocations of a user without owner are reported, not skipped silently) and the {@see UserSessionsInterface} alias.
 */
final class SessionOwnerResolverTest extends SessionsKernelTestCase
{
    public function testDefaultResolverAndPortsAreWired(): void
    {
        $this->boot(services: static function (ContainerConfigurator $c): void {
            $c->services()->alias('test.owner_resolver', SessionOwnerResolverInterface::class)->public();
            $c->services()->alias('test.user_sessions', UserSessionsInterface::class)->public();
        });
        self::assertInstanceOf(SessionUserResolver::class, $this->container()->get('test.owner_resolver'));
        self::assertSame($this->manager(), $this->container()->get('test.user_sessions'));

        $alice = $this->member('alice@example.com');
        self::assertEquals(new SessionUser(Member::class, (string) $alice->getId()), $this->manager()->owner($alice));
        $this->signIn('alice@example.com');
        $rows = $this->manager()->listRowsFor($alice);
        self::assertCount(1, $rows);
        self::assertContainsOnlyInstancesOf(SessionRow::class, $rows);
        self::assertFalse($rows[0]->current);
    }

    public function testAHostResolverMapsTheSecurityAdapterAndTheEntityToOneOwner(): void
    {
        $this->bootWithHostResolver();
        $alice = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        self::assertCount(2, $this->store()->allFor(new SessionUser('member', 'alice@example.com')));

        // The application revokes with its non-entity security user: the rows written at sign-in are found.
        self::assertSame(2, $this->manager()->revokeAll(new SecurityMember('alice@example.com')));
        self::assertCount(0, $this->rowsOf($alice));

        // The Doctrine listener sees the entity: same owner, so the password change reaches the new rows too.
        $this->signIn('alice@example.com');
        $alice = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $alice->setPassword('again');
        $this->em()->flush();
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testRevokingAUserWithoutOwnerIsLogged(): void
    {
        $logger = $this->bootWithHostResolver();
        $logger->take();

        self::assertSame(0, $this->manager()->revokeAll(new stdClass(), null, RevocationReason::PasswordChanged));
        self::assertFalse($this->manager()->revoke(new stdClass(), 'x'));
        self::assertSame(0, $this->manager()->revokeOthers(new stdClass(), null));
        self::assertSame(0, $this->manager()->revokeDevice(new stdClass(), 'd'));
        self::assertSame(0, $this->manager()->forgetUser(new stdClass()));
        $records = $logger->take();
        self::assertSame(['revoke_all', 'revoke', 'revoke_others', 'revoke_device', 'forget_user'], array_map(static fn (array $r): mixed => $r['context']['operation'] ?? null, $records));
        self::assertSame('warning', $records[0]['level'] ?? null);
        self::assertSame(stdClass::class, $records[0]['context']['user_class'] ?? null);
        // listFor / owner are lookups, not revocations: nothing logged.
        self::assertSame([], $this->manager()->listFor(new stdClass()));
        self::assertSame([], $logger->take());
    }

    public function testRevokingAUserWithoutOwnerCanThrow(): void
    {
        $this->boot(['owner' => ['on_unresolved' => 'exception']]);

        try {
            $this->manager()->revokeAll(new stdClass());
            self::fail('Expected an exception.');
        } catch (UnresolvedSessionOwnerException $exception) {
            self::assertSame(stdClass::class, $exception->userClass);
            self::assertSame('revoke_all', $exception->operation);
            self::assertStringContainsString('stdClass', $exception->getMessage());
        }
    }

    public function testDoctrineListenersReportAnEntityWithoutOwner(): void
    {
        $logger = $this->bootWith(NoOwnerResolver::class, 'log', ['user_removal' => ['delete_sessions' => true]]);
        $alice  = $this->member('alice@example.com');
        $logger->take();
        $alice->setPassword('again');
        $this->em()->flush();
        $this->em()->remove($alice);
        $this->em()->flush();
        self::assertSame(['password_change', 'user_removal'], array_map(static fn (array $r): mixed => $r['context']['operation'] ?? null, $logger->take()));
    }

    public function testDoctrineListenersThrowBeforeTheWriteWhenAsked(): void
    {
        $this->bootWith(NoOwnerResolver::class, 'exception');
        $bob = $this->member('bob@example.com');
        $bob->setPassword('again');

        $this->expectException(UnresolvedSessionOwnerException::class);
        $this->em()->flush();
    }

    private function bootWithHostResolver(): RecordingLogger
    {
        $this->boot(['owner' => ['resolver' => 'test.owners']], services: static function (ContainerConfigurator $c): void {
            $c->services()->set('test.owners', MemberOwnerResolver::class);
            $c->services()->set('logger', RecordingLogger::class)->public();
        });
        $logger = $this->container()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * @param class-string<SessionOwnerResolverInterface> $resolver
     * @param array<string, mixed> $sessions
     */
    private function bootWith(string $resolver, string $onUnresolved, array $sessions = []): RecordingLogger
    {
        $this->boot(['owner' => ['resolver' => 'test.owners', 'on_unresolved' => $onUnresolved]] + $sessions, services: static function (ContainerConfigurator $c) use ($resolver): void {
            $c->services()->set('test.owners', $resolver);
            $c->services()->set('logger', RecordingLogger::class)->public();
        });
        $logger = $this->container()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }
}
