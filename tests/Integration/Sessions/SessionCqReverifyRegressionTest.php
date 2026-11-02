<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

use function strtok;
use function time;
use function usleep;

/**
 * SEC-004 Appendix CQ re-verification (1.10 on 983a044) PoCs turned into regression tests: N1 the deferred re-issue of
 * the kept session survives requests that cannot re-issue it, N2 a sign-in in the revocation's second gets a working
 * cookie from the epoch second on.
 */
final class SessionCqReverifyRegressionTest extends SessionsKernelTestCase
{
    /** CQ N1 (PoC P11): an anonymous request on another firewall context keeps the pending re-issue. */
    public function testDeferredReissueSurvivesARequestOnAnotherFirewall(): void
    {
        $this->bootEpoch(['back_firewall' => true]);
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com', remember: true);
        $stolen = $this->rememberMe($this->signIn('alice@example.com', remember: true));
        $old    = $this->rememberMe($laptop);
        $this->tick();

        $crawler = $laptop->request('GET', '/account/sessions');
        $laptop->submit($crawler->filter('form[action="/account/sessions/revoke-others"]')->form());
        $laptop->request('GET', '/back/page');
        self::assertSame(200, $laptop->getResponse()->getStatusCode());
        $this->tick();
        $laptop->request('GET', '/page');
        self::assertSame(200, $laptop->getResponse()->getStatusCode());
        $fresh = $this->rememberMe($laptop);
        self::assertNotSame($old->getValue(), $fresh->getValue(), 'The kept session got its fresh cookie.');
        self::assertSame('alice@example.com', $this->visit($fresh));
        self::assertSame('anonymous', $this->visit($old));
        self::assertSame('anonymous', $this->visit($stolen));
    }

    /** CQ N2: a sign-in in the same second as the revocation gets a stale cookie, re-issued at the epoch second. */
    public function testSignInInTheRevocationSecondGetsAWorkingCookie(): void
    {
        $this->bootEpoch();
        $alice = $this->member('alice@example.com');
        for ($try = 0; $try < 5; ++$try) {
            $this->tick();
            $start = time();
            $this->manager()->revokeAll($alice);
            $browser = $this->signIn('alice@example.com', remember: true);
            if (time() === $start) {
                break;
            }
        }
        $early = $this->rememberMe($browser);
        self::assertSame('anonymous', $this->visit($early), 'Issued before the epoch second: refused as a remember-me login.');

        $this->tick();
        $browser->request('GET', '/page');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $fresh = $this->rememberMe($browser);
        self::assertNotSame($early->getValue(), $fresh->getValue());
        self::assertSame('alice@example.com', $this->visit($fresh));
    }

    /**
     * @param array{back_firewall?: bool} $options
     */
    private function bootEpoch(array $options = []): void
    {
        $this->boot(['remember_me_epoch' => ['enabled' => true]], options: $options);
        $em = $this->em();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(UserSessionEpoch::class)]);
        // Symfony's remember-me handlers use the real time: align the clock with it.
        $this->clock()->sleep(time() - $this->clock()->now()->getTimestamp());
    }

    /** Waits for the next real second and moves the clock there. */
    private function tick(): void
    {
        $now = time();
        while (time() === $now) {
            usleep(20000);
        }
        $this->clock()->sleep(time() - $this->clock()->now()->getTimestamp());
    }

    private function rememberMe(KernelBrowser $browser): Cookie
    {
        return $browser->getCookieJar()->get('REMEMBERME') ?? self::fail('No remember-me cookie.');
    }

    /** Visits `/page` with only a remember-me cookie; returns the signed-in identifier (or "anonymous"). */
    private function visit(Cookie $cookie): string
    {
        $browser = $this->browser();
        $browser->getCookieJar()->set(new Cookie($cookie->getName(), $cookie->getValue()));
        $browser->request('GET', '/page');
        if ($browser->getResponse()->isRedirect()) {
            return 'anonymous';
        }

        return (string) strtok($this->body($browser), "\n");
    }
}
