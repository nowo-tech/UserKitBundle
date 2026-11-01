<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Ui\SessionsPages;
use Nowo\UserKitBundle\Tests\Support\SharedMemoryPool;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Http\RememberMe\SignatureRememberMeHandler;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Regression tests of the re-verification of the REQ-SEC-004 fixes (Appendix CC, "Re-verification (7d794a7)"):
 * N1 custom remember-me handler, N2 wrapped per-server pools, N3 unambiguous admin token ids, N4 protocol-relative
 * late redirect.
 */
final class SessionRecheckRegressionTest extends SessionsKernelTestCase
{
    /**
     * N1: with `remember_me.service` the cookie name comes from the firewall's remember-me authenticator and the
     * custom handler clears its own cookie, so the revoked browser does not log back in.
     */
    public function testCustomRememberMeHandlerCookieIsStrippedAndCleared(): void
    {
        $services = static function (ContainerConfigurator $c): void {
            $s = $c->services();
            $s->set('test.rm_hasher', SignatureHasher::class)->args([service('property_accessor'), ['password'], 'test-secret']);
            $s->set('test.rm', SignatureRememberMeHandler::class)->args([
                service('test.rm_hasher'),
                service('security.user.provider.concrete.members'),
                service('request_stack'),
                ['name' => 'CUSTOM_RM', 'lifetime' => 31536000, 'path' => '/page', 'domain' => null, 'secure' => false, 'httponly' => true, 'samesite' => null, 'always_remember_me' => false, 'remember_me_parameter' => '_remember_me'],
            ]);
        };
        $this->boot(services: $services, options: ['remember_me' => ['service' => 'test.rm', 'name' => 'CUSTOM_RM']]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        $phone->request('GET', '/page');
        self::assertNotNull($phone->getCookieJar()->get('CUSTOM_RM', '/page'));

        $this->manager()->revokeAll($alice);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        $cleared = array_values(array_filter($phone->getResponse()->headers->getCookies(), static fn (\Symfony\Component\HttpFoundation\Cookie $c): bool => $c->getName() === 'CUSTOM_RM'));
        self::assertCount(1, $cleared, 'Cleared once, by the custom handler.');
        self::assertTrue($cleared[0]->isCleared());
        self::assertSame('/page', $cleared[0]->getPath());
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');
        self::assertCount(0, $this->rowsOf($alice));
    }

    /**
     * N2: a tag-aware framework pool over the filesystem adapter is still a per-server pool.
     */
    public function testWrappedPerServerPoolsAreRefused(): void
    {
        $services = static function (ContainerConfigurator $c): void {
            $c->extension('framework', ['cache' => ['pools' => ['test.tagged_pool' => ['adapter' => 'cache.adapter.filesystem', 'tags' => true]]]]);
        };
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only stores on one server');
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.tagged_pool']], services: $services);
    }

    /**
     * N3: profile / user ids containing `_` cannot collide.
     */
    public function testAdminTokenIdsAreUnambiguous(): void
    {
        self::assertNotSame(SessionsPages::adminRevokeAllTokenId('a_b', 'c'), SessionsPages::adminRevokeAllTokenId('a', 'b_c'));
        self::assertNotSame(SessionsPages::adminRevokeTokenId('a_b', 'c', str_repeat('f', 32)), SessionsPages::adminRevokeTokenId('a', 'b_c', str_repeat('f', 32)));
        self::assertSame('nowo_user_kit_sessions_admin_revoke_all/default/7', SessionsPages::adminRevokeAllTokenId('default', '7'));
    }

    /**
     * N4: a request path `//evil.example/x` (host catch-all route) is redirected to `/evil.example/x`, never to the
     * protocol-relative `//evil.example/x`.
     */
    public function testLateRedirectIsNeverProtocolRelative(): void
    {
        $this->boot(['revocation' => ['store' => 'cache', 'cache_pool' => 'test.revocations']], services: static function (ContainerConfigurator $c): void {
            $c->services()->set('test.revocations', SharedMemoryPool::class)->public();
        }, options: ['catch_all' => true]);
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com');
        $sid   = (string) $phone->getCookieJar()->get('MOCKSESSID')?->getValue();
        $this->store()->deleteFor($this->manager()->owner($alice) ?? self::fail());
        $this->clock()->sleep(301);
        $phone->getCookieJar()->set(new Cookie('MOCKSESSID', $sid, null, '/', 'localhost'));
        $phone->request('GET', 'http://localhost//evil.example/x?y=1');
        self::assertSame(302, $phone->getResponse()->getStatusCode(), $this->body($phone));
        self::assertSame('/evil.example/x?y=1', $phone->getResponse()->headers->get('Location'));
    }
}
