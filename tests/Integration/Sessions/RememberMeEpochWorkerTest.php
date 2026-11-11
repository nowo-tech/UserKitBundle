<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Epoch\RememberMeEpoch;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use ReflectionProperty;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_diff;
use function array_map;
use function array_values;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function strtok;
use function time;
use function usleep;

/**
 * REQ-RUNTIME-002 (FrankenPHP worker mode, kernel kept alive and NOT reset): every request goes through the
 * `http_kernel` service of one booted kernel, so the services resetter (`kernel.reset`) never runs between requests.
 *
 * Before 1.14.2, `RememberMeEpoch::$kept` (the session kept by a revocation of the current request) was only emptied by
 * `kernel.reset`: after alice's "sign out this device" with `invalidateRememberMe`, a later request of **another** of
 * alice's sessions in the same worker matched the stale "current session" and got a fresh remember-me cookie, undoing
 * the invalidation for that browser.
 */
final class RememberMeEpochWorkerTest extends SessionsKernelTestCase
{
    /** Requests in the many-request test (alice and bob alternating). */
    private const ITERATIONS = 1000;

    /** Allowed memory growth between the warm-up and the last request. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    public function testARevocationOfOneRequestNeverKeepsAnotherSessionOnALaterRequest(): void
    {
        $this->bootEpoch();
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $laptop          = $this->workerSignIn('alice@example.com');
        [$lost, $lostId] = $this->workerSignInWithId($alice);
        $phone           = $this->workerSignIn('alice@example.com');
        $bob             = $this->workerSignIn('bob@example.com');
        self::assertSame('alice@example.com', $this->who($lost));
        $phoneCookie  = $this->rememberMe($phone);
        $laptopCookie = $this->rememberMe($laptop);
        $this->tick();

        // Alice, on the laptop: "sign out the lost device" and void the remember-me cookies (the laptop is kept).
        $laptop->request('GET', '/page/revoke-remember-me?id=' . $lostId);
        self::assertSame('revoked', $laptop->getInternalResponse()->getContent());
        self::assertSame([], $this->memo(), 'The revocation\'s own response empties the memo.');
        self::assertSame('bob@example.com', $this->who($bob));
        $this->tick();

        // Alice's phone (not the kept session) carries its old remember-me cookie: it must not get a fresh one.
        self::assertSame('alice@example.com', $this->who($phone), 'The phone keeps its session.');
        self::assertSame('bob@example.com', $this->who($bob));
        self::assertSame($phoneCookie->getValue(), $this->rememberMe($phone)->getValue(), 'Only the kept session gets a fresh cookie.');
        // The laptop (kept) gets its fresh cookie from the deferred marker in its own session.
        self::assertSame('alice@example.com', $this->who($laptop));
        self::assertNotSame($laptopCookie->getValue(), $this->rememberMe($laptop)->getValue());

        self::assertSame('anonymous', $this->visit($phoneCookie), 'The phone\'s cookie predates the epoch.');
        self::assertSame('alice@example.com', $this->visit($this->rememberMe($laptop)));
    }

    public function testManyRequestsAlternatingTwoUsersKeepNothingAndMemoryFlat(): void
    {
        $this->bootEpoch();
        $this->member('alice@example.com');
        $this->member('bob@example.com');
        $browsers = ['alice@example.com' => $this->workerSignIn('alice@example.com'), 'bob@example.com' => $this->workerSignIn('bob@example.com')];
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $email   = $i % 2 === 0 ? 'alice@example.com' : 'bob@example.com';
            $browser = $browsers[$email];
            if ($i % 50 === 0) {
                // A revocation that keeps the current session (no matching row: the epoch still moves).
                $browser->request('GET', '/page/revoke-remember-me?id=none');
                self::assertSame('revoked', $browser->getInternalResponse()->getContent());
                self::assertSame([], $this->memo(), sprintf('Request %d: the kept session does not outlive its request.', $i));
            }
            self::assertSame($email, $this->who($browser), sprintf('Request %d: only %s\'s data.', $i, $email));
            self::assertSame([], $this->memo(), sprintf('Request %d: nothing stays in the memo.', $i));
            $browser->getHistory()->clear();
            if ($i === 99) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over the requests.');
    }

    private function bootEpoch(): void
    {
        $this->boot(['remember_me_epoch' => ['enabled' => true]]);
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

    /**
     * A browser on the kernel's `http_kernel` service: no `Kernel::handle()`, so no services resetter between requests.
     */
    private function workerBrowser(): HttpKernelBrowser
    {
        self::assertNotNull($this->kernel);
        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        $browser = new HttpKernelBrowser($http);
        $browser->followRedirects(false);

        return $browser;
    }

    private function workerSignIn(string $email): HttpKernelBrowser
    {
        $browser = $this->workerBrowser();
        $browser->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0');
        $browser->request('POST', '/login', ['_username' => $email, '_password' => 'secret', '_remember_me' => 'on']);

        return $browser;
    }

    /**
     * @return array{HttpKernelBrowser, string} the browser and the public id of its new session row
     */
    private function workerSignInWithId(Member $member): array
    {
        $before  = array_map(static fn (UserSession $row): string => $row->getPublicId(), $this->rowsOf($member));
        $browser = $this->workerSignIn($member->getUserIdentifier());
        $after   = array_map(static fn (UserSession $row): string => $row->getPublicId(), $this->rowsOf($member));
        $new     = array_values(array_diff($after, $before));
        self::assertCount(1, $new);

        return [$browser, $new[0]];
    }

    /** GET /page on the browser; the signed-in identifier (or "anonymous"). */
    private function who(HttpKernelBrowser $browser): string
    {
        $browser->request('GET', '/page');
        $response = $browser->getInternalResponse();
        if ($response->getStatusCode() !== 200) {
            return 'anonymous';
        }

        return (string) strtok($response->getContent(), "\n");
    }

    private function rememberMe(HttpKernelBrowser $browser): Cookie
    {
        return $browser->getCookieJar()->get('REMEMBERME') ?? self::fail('No remember-me cookie.');
    }

    /**
     * @return array<mixed>
     */
    private function memo(): array
    {
        $epoch = $this->container()->get(RememberMeEpoch::class);
        self::assertInstanceOf(RememberMeEpoch::class, $epoch);
        $kept = (new ReflectionProperty(RememberMeEpoch::class, 'kept'))->getValue($epoch);
        self::assertIsArray($kept);

        return $kept;
    }

    /** Visits `/page` with only a remember-me cookie (a fresh browser); returns the signed-in identifier. */
    private function visit(Cookie $cookie): string
    {
        $browser = $this->workerBrowser();
        $browser->getCookieJar()->set(new Cookie($cookie->getName(), $cookie->getValue()));

        return $this->who($browser);
    }
}
