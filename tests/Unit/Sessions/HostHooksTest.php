<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Sessions;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Sessions\Epoch\DoctrineSessionEpochStore;
use Nowo\UserKitBundle\Sessions\Epoch\Entity\UserSessionEpoch;
use Nowo\UserKitBundle\Sessions\Epoch\EpochRememberMeHandler;
use Nowo\UserKitBundle\Sessions\Epoch\RememberMeEpoch;
use Nowo\UserKitBundle\Sessions\Epoch\SessionEpochStoreInterface;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\EventListener\UserRemovalListener;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionLimit;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\SessionUserResolver;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Support\FakeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\InMemoryTokenProvider;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

use function hash_hmac;
use function time;

final class HostHooksTest extends TestCase
{
    public function testSessionLimitCarriesHostValues(): void
    {
        $limit = new SessionLimit(2, maxTrustedDevices: 0, extra: ['plan' => 'pro']);
        self::assertTrue($limit->limitsTrustedDevices());
        self::assertSame('pro', $limit->extra('plan'));
        self::assertSame('x', $limit->extra('missing', 'x'));
        self::assertFalse(SessionLimit::unlimited()->limitsTrustedDevices());

        $this->expectException(InvalidArgumentException::class);
        new SessionLimit(maxTrustedDevices: -1);
    }

    public function testCustomReasons(): void
    {
        self::assertTrue(RevocationReason::isValidCustom('link'));
        self::assertFalse(RevocationReason::isValidCustom('admin'));
        self::assertFalse(RevocationReason::isValidCustom('Link'));
        self::assertFalse(RevocationReason::isValidCustom(str_repeat('a', 33)));
        self::assertFalse(RevocationReason::isValidCustom("link\n"), 'I1: no trailing newline.');
        $event = new UserSessionsRevokedEvent(new SessionUser('t', '1'), null, null, RevocationReason::Admin, []);
        self::assertSame('admin', $event->reasonCode());
        self::assertSame([], $event->context);
    }

    public function testEpochHandlerSignatureCookies(): void
    {
        $user  = new InMemoryUser('alice', null);
        $inner = $this->inner($user);
        $epoch = $this->epoch(1000);
        $owner = new SessionUserResolver();

        $handler = new EpochRememberMeHandler($inner, $epoch, $owner, 100);
        self::assertSame($user, $handler->consumeRememberMeCookie(new RememberMeDetails('alice', 1100, 'sig')), 'Issued at the epoch.');
        $handler->createRememberMeCookie($user);
        $handler->clearRememberMeCookie();

        // F4: an issue time in the future (signed under a longer lifetime) is unknown: stale when an epoch exists.
        $future = new RememberMeDetails('alice', time() + 100 + 3600, 'sig');
        $none   = $this->createStub(SessionEpochStoreInterface::class);
        self::assertSame($user, (new EpochRememberMeHandler($inner, new RememberMeEpoch($none, $owner, new MockClock(), 's'), $owner, 100))->consumeRememberMeCookie($future));
        try {
            $handler->consumeRememberMeCookie($future);
            self::fail('A cookie with an unknown issue time passed the epoch.');
        } catch (AuthenticationException) {
        }

        $this->expectException(AuthenticationException::class);
        $handler->consumeRememberMeCookie(new RememberMeDetails('alice', 1099, 'sig'));
    }

    public function testEpochHandlerPersistentCookiesUseTheServerSideLastUse(): void
    {
        $user   = new InMemoryUser('alice', null);
        $tokens = new InMemoryTokenProvider();
        $tokens->createNewToken(new PersistentToken('alice', 'series', 'value', new DateTimeImmutable('@999')));
        $handler = new EpochRememberMeHandler($this->inner($user), $this->epoch(1000), new SessionUserResolver(), 100, $tokens);

        // Malformed: left to the inner handler.
        self::assertSame($user, $handler->consumeRememberMeCookie(new RememberMeDetails('alice', 99999, 'no-separator')));

        $this->expectException(AuthenticationException::class);
        // The (unsigned) expiry of the cookie is ignored.
        $handler->consumeRememberMeCookie(new RememberMeDetails('alice', 99999, 'series:value'));
    }

    public function testReissueOnlyForTheKeptSessionAtOrAfterTheEpoch(): void
    {
        $user   = new InMemoryUser('alice', null);
        $owner  = new SessionUser(InMemoryUser::class, 'alice');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $inner = $this->createMock(RememberMeHandlerInterface::class);
        $inner->expects(self::exactly(2))->method('createRememberMeCookie')->with($user);
        $store     = $this->createStub(SessionEpochStoreInterface::class);
        $clock     = new MockClock('2026-11-02 10:00:00');
        $context   = new FirewallContext([], null, null, new FirewallConfig('main', 'user_checker'));
        $firewalls = new FirewallMap(new ServiceLocator(['ctx.main' => static fn (): FirewallContext => $context]), ['ctx.main' => null]);
        $epoch     = new RememberMeEpoch($store, new SessionUserResolver(), $clock, 's', $tokens, $firewalls, new ServiceLocator(['main' => static fn (): RememberMeHandlerInterface => $inner]), ['main' => 'REMEMBERME']);
        $event     = static fn (Request $r, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent => new ResponseEvent(self::kernel(), $r, $type, new Response());

        [$request, $session] = $this->request();
        $hash                = hash_hmac('sha256', $session->getId(), 's');
        $epoch->onResponse($event($request));
        $at = $epoch->bump($owner);
        self::assertSame($clock->now()->getTimestamp() + 1, $at, 'The epoch is the next whole second.');
        $epoch->keep($owner, $at, $hash);
        $epoch->onResponse($event($request, HttpKernelInterface::SUB_REQUEST));
        // Another session of the user (signed out by the revocation): nothing.
        [$other] = $this->request();
        $epoch->onResponse($event($other));
        // Same second as the epoch: deferred to the session's next request.
        $epoch->onResponse($event($request));
        self::assertSame(['u' => $owner->key(), 'e' => $at], $session->get(RememberMeEpoch::PENDING_ATTRIBUTE));
        $epoch->reset();
        $epoch->onResponse($event($request));
        self::assertNotNull($session->get(RememberMeEpoch::PENDING_ATTRIBUTE), 'Still too early.');
        $clock->sleep(1);
        $epoch->onResponse($event($request));
        self::assertNull($session->get(RememberMeEpoch::PENDING_ATTRIBUTE));

        // Due, but the request carries no remember-me cookie: nothing to refresh.
        $session->set(RememberMeEpoch::PENDING_ATTRIBUTE, ['u' => $owner->key(), 'e' => $at]);
        $request->cookies->remove('REMEMBERME');
        $epoch->onResponse($event($request));
        self::assertNull($session->get(RememberMeEpoch::PENDING_ATTRIBUTE));
        $request->cookies->set('REMEMBERME', 'x');

        // A pending re-issue of another owner is dropped.
        $session->set(RememberMeEpoch::PENDING_ATTRIBUTE, ['u' => 'x#1', 'e' => $at]);
        $epoch->onResponse($event($request));
        self::assertNull($session->get(RememberMeEpoch::PENDING_ATTRIBUTE));

        // revoke(…, invalidateRememberMe): the current session, unless it was the revoked one.
        $epoch->keep($owner, $at, null, true, [$hash]);
        $epoch->onResponse($event($request));
        $epoch->keep($owner, $at, null, true, []);
        $epoch->onResponse($event($request));
        // Already handled (the handler set the cookie attribute): not twice.
        $request->attributes->set(ResponseListener::COOKIE_ATTR_NAME, true);
        $epoch->onResponse($event($request));
        $epoch->forget($owner);
        $epoch->keep($owner, $at, null);
        $epoch->reset();
    }

    /**
     * @return array{Request, Session}
     */
    private function request(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set(UserSessionManager::ATTRIBUTE, ['main' => []]);
        $request = Request::create('/');
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());
        $request->cookies->set('REMEMBERME', 'x');

        return [$request, $session];
    }

    public function testEpochStoreRaceAndUnmappedEntity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('insert')->willThrowException(new UniqueConstraintViolationException(new class('race') extends AbstractException {}, null));
        $connection->expects(self::once())->method('executeStatement');
        $metadata = new ClassMetadata(UserSessionEpoch::class);
        $metadata->setPrimaryTable(['name' => UserSessionEpoch::TABLE]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($em);

        $store = new DoctrineSessionEpochStore($doctrine);
        $store->bump(new SessionUser('t', '1'), new DateTimeImmutable());
        self::assertNull($store->epoch(new SessionUser('t', '1')));

        $entity = new UserSessionEpoch('t', '1', new DateTimeImmutable('@5'));
        self::assertNull($entity->getId());
        self::assertSame('t', $entity->getUserType());
        self::assertSame('1', $entity->getUserId());
        self::assertSame(5, $entity->getEpochAt()->getTimestamp());

        $none = $this->createStub(ManagerRegistry::class);
        $this->expectException(LogicException::class);
        (new DoctrineSessionEpochStore($none, 'other'))->epoch(new SessionUser('t', '1'));
    }

    public function testWiring(): void
    {
        $container = $this->load(['enabled' => true, 'remember_me_epoch' => ['enabled' => true, 'store' => 'app.epochs'], 'user_removal' => ['delete_sessions' => true], 'limit' => ['max_trusted_devices' => 4], 'purge' => ['revoked_retention' => 60], 'early_check' => ['json_response' => true]]);
        self::assertSame('app.epochs', (string) $container->getAlias(SessionEpochStoreInterface::class));
        self::assertTrue($container->hasDefinition(RememberMeEpoch::class));
        self::assertTrue($container->hasDefinition(UserRemovalListener::class));

        $default = $this->load(['enabled' => true, 'remember_me_epoch' => ['enabled' => true]]);
        self::assertSame(DoctrineSessionEpochStore::class, (string) $default->getAlias(SessionEpochStoreInterface::class));
        self::assertFalse($this->load(['enabled' => true])->hasDefinition(RememberMeEpoch::class));

        $prepend = new ContainerBuilder();
        $prepend->registerExtension(new NowoUserKitExtension());
        $prepend->registerExtension(new FakeExtension('doctrine'));
        $prepend->loadFromExtension('nowo_user_kit', ['sessions' => ['enabled' => true, 'remember_me_epoch' => ['enabled' => true]]]);
        SessionsLoader::prepend($prepend);
        self::assertArrayHasKey('NowoUserKitSessionEpoch', $prepend->getExtensionConfig('doctrine')[0]['orm']['mappings']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('remember_me_epoch.enabled must be a literal boolean');
        SessionsLoader::load(new ContainerBuilder(), ['enabled' => true, 'web_ui' => ['enabled' => false, 'admin' => true], 'security' => ['allow_unauthenticated' => false], 'remember_me_epoch' => ['enabled' => '%env(X)%']], [], false);
    }

    /**
     * @param array<string, mixed> $sessions
     */
    private function load(array $sessions): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 's');
        (new NowoUserKitExtension())->load([['user_class' => 'App\User', 'sessions' => $sessions]], $container);

        return $container;
    }

    private function inner(UserInterface $user): RememberMeHandlerInterface
    {
        $inner = $this->createStub(RememberMeHandlerInterface::class);
        $inner->method('consumeRememberMeCookie')->willReturn($user);

        return $inner;
    }

    private function epoch(int $at): RememberMeEpoch
    {
        $store = $this->createStub(SessionEpochStoreInterface::class);
        $store->method('epoch')->willReturn($at);

        return new RememberMeEpoch($store, new SessionUserResolver(), new MockClock(), 's');
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
