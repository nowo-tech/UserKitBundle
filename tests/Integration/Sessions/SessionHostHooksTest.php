<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use ArrayObject;
use Closure;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Command\PurgeSessionsCommand;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsDeletedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsPurgedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\EventListener\UserRemovalListener;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionLimitPolicy;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\RoleLimitResolver;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\InMemoryTokenProvider;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Http\RememberMe\SignatureRememberMeHandler;

use function json_decode;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;

/**
 * 1.10.0 host hooks (spec 005): trusted-device limit carried by SessionLimit, remember-me epoch on revokeAll /
 * revokeOthers, host-defined revocation reasons and context, early JSON 401, revoked-row retention and user removal.
 */
final class SessionHostHooksTest extends SessionsKernelTestCase
{
    public function testLimitsCarryTheTrustedDeviceLimitForTheHost(): void
    {
        $this->boot(['limit' => ['max_sessions' => 3, 'max_trusted_devices' => 2]]);
        $alice = $this->member('alice@example.com');
        $limit = $this->manager()->limitFor($alice);
        self::assertSame(3, $limit->maxSessions);
        self::assertSame(2, $limit->maxTrustedDevices);
        self::assertTrue($limit->limitsTrustedDevices());
        $this->tearDown();

        $this->boot(['limit' => ['resolver' => 'test.limits']], services: static function (ContainerConfigurator $c): void {
            $c->services()->set('test.limits', RoleLimitResolver::class);
        });
        $limit = $this->manager()->limitFor($this->member('bob@example.com'));
        self::assertSame(SessionLimitPolicy::RejectNew, $limit->policy);
        self::assertNull($limit->maxTrustedDevices);
    }

    public function testRememberMeSurvivesRevocationByDefault(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        $copy  = $this->rememberMe($phone);
        self::assertFalse($this->manager()->invalidatesRememberMe());

        $this->manager()->revokeAll($alice);
        self::assertSame('alice@example.com', $this->visit($copy));
    }

    public function testEpochRefusesRememberMeCookiesIssuedBeforeRevokeAll(): void
    {
        $this->bootEpoch();
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        $copy  = $this->rememberMe($phone);
        self::assertSame('alice@example.com', $this->visit($copy), 'Valid before the revocation.');
        self::assertTrue($this->manager()->invalidatesRememberMe());

        $events = $this->recordRevocations();
        $this->tick();
        // The row may be gone already (purged, garbage-collected): the epoch still applies.
        $this->store()->deleteFor(new SessionUser(Member::class, (string) $alice->getId()));
        self::assertSame(0, $this->manager()->revokeAll($alice));
        self::assertTrue(($events[0] ?? self::fail())->rememberMeInvalidated);

        $thief = $this->browser();
        $thief->getCookieJar()->set($copy);
        $thief->request('GET', '/page');
        self::assertRedirectsTo($thief, '/login');
        self::assertNull($thief->getCookieJar()->get('REMEMBERME'), 'The refused cookie is cleared.');

        // A new sign-in after the revocation gets a cookie that works.
        $again = $this->signIn('alice@example.com', remember: true);
        self::assertSame('alice@example.com', $this->visit($this->rememberMe($again)));
    }

    public function testRevokeOthersReissuesTheCookieOfTheKeptSession(): void
    {
        $this->bootEpoch();
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com', remember: true);
        $phone  = $this->signIn('alice@example.com', remember: true);
        $before = $this->rememberMe($laptop);
        $stolen = $this->rememberMe($phone);
        $this->tick();

        $crawler = $laptop->request('GET', '/account/sessions');
        $laptop->submit($crawler->filter('form[action="/account/sessions/revoke-others"]')->form());
        self::assertRedirectsTo($laptop, '/account/sessions');
        $fresh = $this->rememberMe($laptop);
        self::assertNotSame($before->getValue(), $fresh->getValue(), 'The kept session got a fresh cookie.');

        self::assertSame('anonymous', $this->visit($stolen, '/login'));
        self::assertSame('anonymous', $this->visit($before, '/login'));
        self::assertSame('alice@example.com', $this->visit($fresh));

        // Without a remember-me cookie, nothing is issued.
        $tablet  = $this->signIn('alice@example.com');
        $crawler = $tablet->request('GET', '/account/sessions');
        $tablet->submit($crawler->filter('form[action="/account/sessions/revoke-others"]')->form());
        self::assertNull($tablet->getCookieJar()->get('REMEMBERME'));
    }

    public function testPasswordChangeReissuesTheCookieOfTheChangingSession(): void
    {
        $this->bootEpoch();
        $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com', remember: true);
        $this->tick();
        $laptop->request('GET', '/page/password');
        $fresh = $this->rememberMe($laptop);
        self::assertSame('alice@example.com', $this->visit($fresh));
    }

    public function testEpochWithPersistentTokens(): void
    {
        $this->bootEpoch(options: ['remember_me' => ['token_provider' => ['service' => 'test.tokens']]], services: static function (ContainerConfigurator $c): void {
            $c->services()->set('test.tokens', InMemoryTokenProvider::class)->public();
        });
        $alice = $this->member('alice@example.com');
        $phone = $this->signIn('alice@example.com', remember: true);
        $copy  = $this->rememberMe($phone);
        $this->tick();
        $this->manager()->revokeAll($alice);

        $thief = $this->browser();
        $thief->getCookieJar()->set($copy);
        $thief->request('GET', '/page');
        self::assertRedirectsTo($thief, '/login');
    }

    public function testEpochLeavesCustomHandlersAlone(): void
    {
        $services = static function (ContainerConfigurator $c): void {
            $s = $c->services();
            $s->set('test.rm_hasher', SignatureHasher::class)->args([service('property_accessor'), ['password'], 'test-secret']);
            $s->set('test.rm', SignatureRememberMeHandler::class)->args([
                service('test.rm_hasher'),
                service('security.user.provider.concrete.members'),
                service('request_stack'),
                ['name' => 'CUSTOM_RM', 'lifetime' => 31536000, 'path' => '/', 'domain' => null, 'secure' => false, 'httponly' => true, 'samesite' => null, 'always_remember_me' => false, 'remember_me_parameter' => '_remember_me'],
            ]);
        };
        $this->bootEpoch(options: ['remember_me' => ['service' => 'test.rm', 'name' => 'CUSTOM_RM']], services: $services);
        self::assertTrue($this->manager()->invalidatesRememberMe());
    }

    public function testErasureAndUserRemovalForgetTheEpoch(): void
    {
        $this->bootEpoch(['user_removal' => ['delete_sessions' => true]]);
        $alice = $this->member('alice@example.com');
        $owner = new SessionUser(Member::class, (string) $alice->getId());
        $phone = $this->signIn('alice@example.com');
        $this->manager()->revokeOthers($alice, null);
        self::assertSame(1, $this->epochRows());

        $deleted = [];
        $this->dispatcher()->addListener(UserSessionsDeletedEvent::class, static function (UserSessionsDeletedEvent $e) use (&$deleted): void {
            $deleted[] = $e;
        });
        $this->signIn('alice@example.com');
        $alice = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $this->em()->remove($alice);
        $this->em()->flush();
        self::assertSame(0, $this->epochRows());
        self::assertSame([], $this->store()->allFor($owner));
        self::assertCount(1, $deleted);
        self::assertSame(2, $deleted[0]->deleted);
        self::assertNull($deleted[0]->user);
        $phone->request('GET', '/page');
        self::assertRedirectsTo($phone, '/login');

        $this->manager()->forgetEpoch($owner);
        self::assertSame(0, $this->manager()->forgetUser(new stdClass()));
    }

    public function testUserRemovalWaitsForTheFlushAndIsOptIn(): void
    {
        $this->boot(['user_removal' => ['delete_sessions' => true]]);
        $alice = $this->member('alice@example.com');
        $owner = new SessionUser(Member::class, (string) $alice->getId());
        $this->signIn('alice@example.com');
        $listener = $this->container()->get(UserRemovalListener::class);
        self::assertInstanceOf(UserRemovalListener::class, $listener);

        // Removed, then the unit of work cleared before the flush: nothing happens.
        $alice = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $this->em()->remove($alice);
        $this->em()->clear();
        $this->em()->flush();
        self::assertCount(1, $this->store()->allFor($owner));

        // Removed but another flush runs first (the entity is still scheduled): nothing yet; reset() drops it.
        $alice = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $this->em()->remove($alice);
        $this->em()->persist($alice);
        $this->em()->flush();
        self::assertCount(1, $this->rowsOf($alice));
        $listener->reset();
        $this->tearDown();

        $this->boot();
        $alice = $this->member('alice@example.com');
        $owner = new SessionUser(Member::class, (string) $alice->getId());
        $this->signIn('alice@example.com');
        self::assertFalse($this->container()->has(UserRemovalListener::class));
        $alice = $this->em()->find(Member::class, $alice->getId()) ?? self::fail();
        $this->em()->remove($alice);
        $this->em()->flush();
        self::assertCount(1, $this->store()->allFor($owner), 'Off by default: the host deletes the rows (forgetUser) or the purge does.');
        self::assertSame(1, $this->manager()->forgetOwner($owner));
    }

    public function testHostDefinedReasonsAndContext(): void
    {
        $this->boot();
        $alice  = $this->member('alice@example.com');
        $phone  = $this->signIn('alice@example.com');
        $events = $this->recordRevocations();

        self::assertSame(1, $this->manager()->revokeAll($alice, null, RevocationReason::AccountChanged, null, 'link', ['provider' => 'github']));
        $event = $events[0] ?? self::fail();
        self::assertSame('link', $event->customReason);
        self::assertSame('link', $event->reasonCode());
        self::assertSame(['provider' => 'github'], $event->context);
        self::assertSame(RevocationReason::AccountChanged, $event->reason);
        self::assertFalse($event->rememberMeInvalidated);
        self::assertSame('link', $this->store()->allFor(new SessionUser(Member::class, (string) $alice->getId()))[0]['revoked_reason']);

        $phone->request('GET', '/page');
        $phone->followRedirect();
        $phone->loginUser($alice);
        $phone->request('GET', '/page');
        self::assertStringContainsString('the account was linked to another one', $this->body($phone));

        // Untranslated host reason: the generic message.
        $tablet = $this->signIn('alice@example.com');
        $this->em()->clear();
        $row = $this->manager()->listFor($alice)[0] ?? self::fail();
        self::assertTrue($this->manager()->revoke($alice, $row->getPublicId(), null, 'merged', ['by' => 'host']));
        $tablet->request('GET', '/page');
        $tablet->followRedirect();
        $tablet->loginUser($alice);
        $tablet->request('GET', '/page');
        self::assertStringContainsString('this session was signed out from another one', $this->body($tablet));
        self::assertSame(0, $this->manager()->revokeDevice($alice, 'none', null, null, 'lost_device'));
        self::assertSame(2, $this->manager()->revokeOthers($alice, null, null, 'link'), 'The two browsers signed in again.');
    }

    public function testInvalidHostReasonsAreRefused(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        foreach (['admin', 'Link', 'a-b', ''] as $reason) {
            try {
                $this->manager()->revokeAll($alice, customReason: $reason);
                self::fail('Accepted ' . $reason);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('custom revocation reason', $e->getMessage());
            }
        }
    }

    public function testEarlyCheckJsonResponseIsOptIn(): void
    {
        $this->boot(['early_check' => ['json_response' => true]]);
        $alice = $this->member('alice@example.com');
        $xhr   = $this->signIn('alice@example.com');
        $html  = $this->signIn('alice@example.com');
        $this->manager()->revokeAll($alice);

        $xhr->request('GET', '/page', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(401, $xhr->getResponse()->getStatusCode());
        self::assertSame(['error' => 'session_signed_out'], json_decode($this->body($xhr), true, 512, JSON_THROW_ON_ERROR));
        $html->request('GET', '/page');
        self::assertRedirectsTo($html, '/login');
        $this->tearDown();

        $this->boot();
        $alice = $this->member('alice@example.com');
        $xhr   = $this->signIn('alice@example.com');
        $this->manager()->revokeAll($alice);
        $xhr->request('GET', '/page', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertNotSame(401, $xhr->getResponse()->getStatusCode(), 'Default: the request goes on as a guest.');
    }

    public function testRevokedRowsHaveTheirOwnRetention(): void
    {
        $kernel = $this->boot(['purge' => ['revoked_retention' => 3600]]);
        $alice  = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->signIn('alice@example.com');
        $this->em()->clear();
        $row = $this->manager()->listFor($alice)[0] ?? self::fail();
        $this->manager()->revoke($alice, $row->getPublicId());
        $purged = [];
        $this->dispatcher()->addListener(UserSessionsPurgedEvent::class, static function (UserSessionsPurgedEvent $e) use (&$purged): void {
            $purged[] = $e;
        });

        $tester = new CommandTester((new Application($kernel))->find(PurgeSessionsCommand::NAME));
        $tester->execute([]);
        self::assertStringContainsString('Deleted 0 session row(s)', $tester->getDisplay());
        $this->clock()->sleep(3601);
        $tester->execute([]);
        self::assertStringContainsString('Deleted 1 session row(s)', $tester->getDisplay());
        self::assertStringContainsString('revoked before', $tester->getDisplay());
        self::assertCount(1, $this->store()->allFor(new SessionUser(Member::class, (string) $alice->getId())));
        self::assertNotNull($purged[1]->revokedBefore ?? null);
        $this->tearDown();

        // Longer than the idle window: the revoked row outlives the live rule.
        $kernel = $this->boot(['purge' => ['revoked_retention' => 30 * 86400]]);
        $alice  = $this->member('alice@example.com');
        $this->signIn('alice@example.com');
        $this->manager()->revokeAll($alice);
        $this->clock()->sleep(2 * 86400);
        $tester = new CommandTester((new Application($kernel))->find(PurgeSessionsCommand::NAME));
        $tester->execute([]);
        self::assertCount(1, $this->store()->allFor(new SessionUser(Member::class, (string) $alice->getId())));
    }

    /**
     * @param array<string, mixed> $sessions
     * @param array{remember_me?: array<string, mixed>} $options
     */
    private function bootEpoch(array $sessions = [], array $options = [], ?Closure $services = null): void
    {
        $this->boot(['remember_me_epoch' => ['enabled' => true]] + $sessions, services: $services, options: $options);
        $em = $this->em();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(UserSessionEpoch::class)]);
        // Symfony's remember-me handlers use the real time: align the clock with it.
        $this->clock()->sleep(time() - $this->clock()->now()->getTimestamp());
    }

    /**
     * Waits for the next real second and moves the clock there (a cookie issued before is older than the epoch).
     */
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

    /**
     * Visits `/page` with only a remember-me cookie; returns the signed-in identifier (or "anonymous").
     */
    private function visit(Cookie $cookie, string $expectedRedirect = ''): string
    {
        $browser = $this->browser();
        $browser->getCookieJar()->set(new Cookie($cookie->getName(), $cookie->getValue()));
        $browser->request('GET', '/page');
        if ($browser->getResponse()->isRedirect()) {
            self::assertStringEndsWith($expectedRedirect !== '' ? $expectedRedirect : '/login', (string) $browser->getResponse()->headers->get('Location'));

            return 'anonymous';
        }

        return (string) strtok($this->body($browser), "\n");
    }

    /**
     * @return ArrayObject<int, UserSessionsRevokedEvent>
     */
    private function recordRevocations(): ArrayObject
    {
        /** @var ArrayObject<int, UserSessionsRevokedEvent> $events */
        $events = new ArrayObject();
        $this->dispatcher()->addListener(UserSessionsRevokedEvent::class, static function (UserSessionsRevokedEvent $e) use ($events): void {
            $events[] = $e;
        });

        return $events;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function epochRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . UserSessionEpoch::TABLE);
    }
}
