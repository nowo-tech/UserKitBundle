<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Closure;
use DateTimeImmutable;
use Nowo\UserKitBundle\Sessions\Command\PurgeSessionsCommand;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\HeaderDeviceIdResolver;
use Nowo\UserKitBundle\Tests\Support\SharedMemoryPool;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Touch throttle, idle expiry, cache revocation store (and a lost marker), rows gone, remember-me cookies, the
 * stateless firewall, impersonation, device sign-out, the purge command.
 */
final class SessionMaintenanceTest extends SessionsKernelTestCase
{
    public function testTouchIsThrottledAndIdleSessionsExpire(): void
    {
        $this->boot(['touch_interval' => 300, 'idle_lifetime' => 3600]);
        $alice   = $this->member('alice@example.com');
        $browser = $this->signIn('alice@example.com');
        $created = $this->rowsOf($alice)[0]->getLastSeenAt();

        $this->clock()->sleep(60);
        $browser->request('GET', '/page');
        self::assertEquals($created, $this->rowsOf($alice)[0]->getLastSeenAt());

        $this->clock()->sleep(300);
        $browser->request('GET', '/page');
        self::assertEquals($created->modify('+360 seconds'), $this->rowsOf($alice)[0]->getLastSeenAt());

        $this->clock()->sleep(3601 + 300);
        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');
        $browser->request('GET', '/login');
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testAGoneRowEndsTheSessionAtTheNextTouch(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: $this->arrayPool());
        $alice   = $this->member('alice@example.com');
        $browser = $this->signIn('alice@example.com');
        $this->store()->deleteFor($this->manager()->owner($alice) ?? self::fail());

        // Cache store: no query before the touch interval.
        $browser->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($browser));

        $this->clock()->sleep(301);
        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/page');
        $browser->request('GET', '/page');
        self::assertRedirectsTo($browser, '/login');

        $xhr = $this->signIn('alice@example.com');
        $this->store()->deleteFor($this->manager()->owner($alice) ?? self::fail());
        $this->clock()->sleep(301);
        $xhr->xmlHttpRequest('POST', '/page');
        self::assertSame(401, $xhr->getResponse()->getStatusCode());
        self::assertSame('{"error":"session_signed_out"}', $this->body($xhr));
    }

    public function testCacheStoreSignsARevokedSessionOutBeforeTheFirewall(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: $this->arrayPool());
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        self::assertNotNull($phone->getCookieJar()->get('REMEMBERME'));

        self::assertSame(1, $this->manager()->revokeAll($alice));
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertNull($phone->getCookieJar()->get('REMEMBERME'), 'The remember-me cookie is cleared.');
        $phone->request('GET', '/login');
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
    }

    public function testFlashTellsWhy(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->manager()->revokeAll($alice, null, RevocationReason::PasswordChanged);
        $phone->request('GET', '/page');
        $phone->followRedirect();
        $phone->loginUser($alice);
        $phone->request('GET', '/page');
        self::assertStringContainsString('You were signed out because your password changed.', $this->body($phone));
    }

    public function testStatelessFirewallsAreIgnored(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $api   = $this->browser();
        $api->request('GET', '/api/page', server: ['PHP_AUTH_USER' => 'alice@example.com', 'PHP_AUTH_PW' => 'secret']);
        self::assertStringStartsWith('alice@example.com', $this->body($api));
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testASessionSignedInBeforeTheFeatureIsRegisteredOnItsNextRequest(): void
    {
        $this->boot();
        $alice   = $this->member('alice@example.com');
        $browser = $this->browser();
        $browser->loginUser($alice);
        $browser->request('GET', '/page');
        self::assertCount(1, $this->rowsOf($alice));
        $browser->request('GET', '/page');
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testImpersonationKeepsTheAdministratorSession(): void
    {
        $this->boot();
        $root  = $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $admin = $this->signIn('root@example.com');
        $admin->request('GET', '/page?_switch_user=alice@example.com');
        $admin->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($admin));
        self::assertCount(1, $this->rowsOf($root));
        self::assertCount(0, $this->rowsOf($alice));
    }

    public function testDeviceSignOut(): void
    {
        $this->boot(['client' => ['device_id_resolver' => HeaderDeviceIdResolver::class, 'ip' => 'hash', 'user_agent' => 'none']], services: static function (ContainerConfigurator $c): void {
            $c->services()->set(HeaderDeviceIdResolver::class);
        });
        $alice  = $this->member('alice@example.com');
        $tablet = $this->browser();
        $tablet->setServerParameter('HTTP_X_DEVICE', 'tablet-1');
        $tablet->request('POST', '/login', ['_username' => 'alice@example.com', '_password' => 'secret']);
        $laptop = $this->signIn('alice@example.com');

        $rows = $this->rowsOf($alice);
        self::assertCount(2, $rows);
        self::assertSame('', $rows[0]->getBrowser());
        self::assertFalse($rows[0]->hasDisplayableIp());
        self::assertStringStartsWith('h:', (string) $rows[0]->getIp());

        self::assertSame(1, $this->manager()->revokeDevice($alice, 'tablet-1'));
        $tablet->request('GET', '/page');
        self::assertRedirectsTo($tablet, '/login');
        $laptop->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($laptop));
    }

    public function testPurgeCommand(): void
    {
        $kernel = $this->boot();
        $alice  = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        $this->clock()->sleep(86400 + 301);
        $this->signIn('alice@example.com');

        $tester = new CommandTester((new Application($kernel))->find(PurgeSessionsCommand::NAME));
        self::assertSame(0, $tester->execute(['--batch-size' => '1']));
        self::assertStringContainsString('Deleted 2 session row(s)', $tester->getDisplay());
        self::assertCount(1, $this->rowsOf($alice));
        self::assertSame(2, $tester->execute(['--batch-size' => '0']));
    }

    public function testHelpers(): void
    {
        $this->boot();
        $manager = $this->manager();
        self::assertSame([], $manager->markers(null));
        self::assertNull($manager->currentHash(new Request()));
        self::assertSame([], $manager->listFor(new stdClass()));
        self::assertFalse($manager->revoke(new stdClass(), 'x'));
        self::assertSame(0, $manager->revokeOthers(new stdClass(), null));
        self::assertSame(0, $manager->revokeAll(new stdClass()));
        self::assertSame(0, $manager->revokeDevice(new stdClass(), 'd'));
        self::assertSame([], $this->store()->oldestActive(new SessionUser('t', '1'), new DateTimeImmutable(), null, 0));
        self::assertSame([], $this->store()->revoke(new SessionUser('t', '1'), RevocationReason::Admin, new DateTimeImmutable(), []));
    }

    private function arrayPool(): Closure
    {
        return static function (ContainerConfigurator $c): void {
            $c->services()->set('test.revocations', SharedMemoryPool::class)->public();
        };
    }
}
