<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Closure;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Support\SharedMemoryPool;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Regression tests of the REQ-SEC-004 audit (Appendix CC, 2026-11-01): M1, M2, L1, L2, L3, L5 and the info items on
 * strict user ids and the late-path redirect. Each test is the audit PoC turned into assertions.
 */
final class SessionSecurityRegressionTest extends SessionsKernelTestCase
{
    /**
     * M1: a revoked or idle session is signed out on `_stateless` routes of the stateful firewall too.
     */
    public function testStatelessRoutesAreChecked(): void
    {
        foreach (['database', 'cache'] as $store) {
            $this->boot(['revocation' => ['store' => $store] + ($store === 'cache' ? ['cache_pool' => 'test.revocations'] : [])], services: $this->sharedPool());
            $alice = $this->member('alice@example.com');
            $phone = $this->signIn('alice@example.com');
            $phone->request('GET', '/page/stateless');
            self::assertStringStartsWith('alice@example.com', $this->body($phone), $store);

            $this->manager()->revokeAll($alice);
            $phone->request('GET', '/page/stateless');
            self::assertRedirectsTo($phone, '/login');
            $phone->request('GET', '/page/stateless');
            self::assertRedirectsTo($phone, '/login');
            $this->tearDown();

            $this->boot(['revocation' => ['store' => $store] + ($store === 'cache' ? ['cache_pool' => 'test.revocations'] : [])], services: $this->sharedPool());
            $this->member('alice@example.com');
            $idle = $this->signIn('alice@example.com');
            $this->clock()->sleep(3 * 86400);
            $idle->request('GET', '/page/stateless');
            self::assertRedirectsTo($idle, '/login');
            $this->tearDown();
        }
    }

    /**
     * M1: reading the markers on a `_stateless` route does not count as a session use (like the firewall's
     * ContextListener; debug kernels throw on stateless session use).
     */
    public function testTheEarlyCheckDoesNotUseTheSessionOfStatelessRoutes(): void
    {
        $this->boot();
        $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $phone->request('GET', '/page');
        $data = $phone->getRequest()->getSession()->all();

        $session = new Session(new MockArraySessionStorage());
        $session->replace($data);
        $usage   = $session->getUsageIndex();
        $request = Request::create('/page/stateless');
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());
        $request->attributes->set('_stateless', true);
        $listener = $this->container()->get(SessionRegistryListener::class);
        self::assertInstanceOf(SessionRegistryListener::class, $listener);
        self::assertNotNull($this->kernel);
        $listener->onRequestEarly(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($usage, $session->getUsageIndex());
        self::assertNotSame([], $session->get(UserSessionManager::ATTRIBUTE));
    }

    /**
     * M2: a renamed remember-me cookie is removed and cleared too (read from the firewall configuration).
     */
    public function testRenamedRememberMeCookie(): void
    {
        $this->boot(options: ['remember_me' => ['name' => 'APP_REMEMBER']]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        self::assertNotNull($phone->getCookieJar()->get('APP_REMEMBER'));

        $this->manager()->revokeAll($alice);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertNull($phone->getCookieJar()->get('APP_REMEMBER'));
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertCount(0, $this->rowsOf($alice));
    }

    /**
     * M2: the cookie is cleared with its configured path and domain, so the browser really drops it.
     */
    public function testRememberMeCookieIsClearedWithItsPathAndDomain(): void
    {
        $this->boot(options: ['remember_me' => ['path' => '/page', 'domain' => 'localhost', 'samesite' => 'strict']]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        $phone->request('GET', '/page');
        $this->manager()->revokeAll($alice);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $cleared = null;
        foreach ($phone->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'REMEMBERME') {
                $cleared = $cookie;
            }
        }
        self::assertNotNull($cleared);
        self::assertTrue($cleared->isCleared());
        self::assertSame('/page', $cleared->getPath());
        self::assertSame('localhost', $cleared->getDomain());
        self::assertSame('strict', $cleared->getSameSite());
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertCount(0, $this->rowsOf($alice));
    }

    /**
     * L1: the "Sign out everywhere" token of one user does not sign out another one.
     */
    public function testRevokeAllTokenIsBoundToTheUser(): void
    {
        $this->boot();
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('bob@example.com');
        $admin   = $this->signIn('root@example.com');
        $crawler = $admin->request('GET', '/admin/user-sessions/default/' . $alice->getId());
        $token   = (string) $crawler->filter('form[action$="/revoke-all"] input[name="csrf_only[_token]"]')->attr('value');

        $admin->request('POST', '/admin/user-sessions/default/' . $bob->getId() . '/revoke-all', ['csrf_only' => ['_token' => $token]]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        self::assertCount(1, $this->rowsOf($bob));
        $admin->request('POST', '/admin/user-sessions/default/' . $alice->getId() . '/revoke-all', ['csrf_only' => ['_token' => $token]]);
        self::assertRedirectsTo($admin, '/admin/user-sessions/default/' . $alice->getId());
        self::assertCount(0, $this->rowsOf($alice));
    }

    /**
     * L2: with the cache store the revocation marker outlives the sign-out, so session data written back under the old
     * id (non-locking handler, concurrent request) is still refused.
     */
    public function testCacheStoreRefusesASessionWrittenBack(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: $this->sharedPool());
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $phone->request('GET', '/page');
        $sid      = (string) $phone->getCookieJar()->get('MOCKSESSID')?->getValue();
        $file     = $this->kernel?->getCacheDir() . '/sessions/' . $sid . '.mocksess';
        $snapshot = (string) file_get_contents($file);

        $this->manager()->revokeAll($alice);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');

        file_put_contents($file, $snapshot);
        $replay = $this->browser();
        $replay->getCookieJar()->set(new Cookie('MOCKSESSID', $sid));
        $replay->request('GET', '/page');
        self::assertRedirectsTo($replay, '/login');
    }

    /**
     * L3: the cache store needs an explicit pool shared by every server.
     */
    public function testCacheStoreRequiresASharedPool(): void
    {
        try {
            $this->boot(['revocation' => ['store' => 'cache']]);
            self::fail('A cache store without pool must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('cache_pool is required', $e->getMessage());
        }
        $this->tearDown();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only stores on one server');
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'cache.app']]);
    }

    /**
     * L5: a second-firewall login in a stolen session does not take over the owner's row; revoking it ends the whole
     * session.
     */
    public function testASecondFirewallContextCannotHideTheSession(): void
    {
        $this->boot(options: ['back_firewall' => true]);
        $alice = $this->member('alice@example.com');
        $this->member('mallory@example.com');
        $own    = $this->signIn('alice@example.com');
        $stolen = $this->signIn('alice@example.com');
        $stolen->request('GET', '/page');

        $stolen->request('POST', '/back/login', ['_username' => 'mallory@example.com', '_password' => 'secret']);
        $stolen->request('GET', '/back/page');
        self::assertStringStartsWith('mallory@example.com', $this->body($stolen));
        self::assertCount(2, $this->rowsOf($alice));
        self::assertCount(1, $this->rowsOf($this->find('mallory@example.com')));

        $crawler = $own->request('GET', '/account/sessions');
        $own->submit($crawler->filter('form[action="/account/sessions/revoke-others"]')->form());
        $stolen->request('GET', '/page');
        self::assertRedirectsTo($stolen, '/login');
        $stolen->request('GET', '/back/page');
        self::assertStringStartsWith('anonymous', $this->body($stolen));
        $own->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($own));
    }

    /**
     * Info: `{user}` must match the identifier exactly ("2abc" is not user 2).
     */
    public function testUserIdIsMatchedStrictly(): void
    {
        $this->boot();
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $alice = $this->member('alice@example.com');
        $admin = $this->signIn('root@example.com');
        $admin->request('GET', '/admin/user-sessions/default/' . $alice->getId() . 'abc');
        self::assertSame(404, $admin->getResponse()->getStatusCode());
        $admin->request('GET', '/admin/user-sessions/default/' . $alice->getId());
        self::assertSame(200, $admin->getResponse()->getStatusCode());
    }

    /**
     * Info: the late-path redirect is the request path, never an absolute URL built from the Host header.
     */
    public function testLatePathRedirectIsPathOnly(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: $this->sharedPool());
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $this->store()->deleteFor($this->manager()->owner($alice) ?? self::fail());
        $this->clock()->sleep(301);
        $sid = (string) $phone->getCookieJar()->get('MOCKSESSID')?->getValue();
        $phone->getCookieJar()->set(new Cookie('MOCKSESSID', $sid, null, '/', 'evil.example'));
        $phone->request('GET', 'http://evil.example/page?x=1');
        self::assertSame('/page?x=1', $phone->getResponse()->headers->get('Location'));
    }

    /**
     * Info: anonymous users learn nothing about the users of the administration page (even without host
     * access_control on its prefix).
     */
    public function testAnonymousAdministrationRequestsAreDeniedFirst(): void
    {
        $this->boot(['web_ui' => ['admin_path_prefix' => '/staff/sessions'], 'remember_me_cookies' => ['LEGACY']]);
        $alice     = $this->member('alice@example.com');
        $anonymous = $this->browser();
        $anonymous->request('GET', '/staff/sessions/default/' . $alice->getId());
        self::assertRedirectsTo($anonymous, '/login');
        $anonymous->request('GET', '/staff/sessions/default/999');
        self::assertRedirectsTo($anonymous, '/login');
    }

    private function find(string $email): Member
    {
        $this->em()->clear();
        $member = $this->em()->getRepository(Member::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Member::class, $member);

        return $member;
    }

    private function sharedPool(): Closure
    {
        return static function (ContainerConfigurator $c): void {
            $c->services()->set('test.revocations', SharedMemoryPool::class)->public();
        };
    }
}
