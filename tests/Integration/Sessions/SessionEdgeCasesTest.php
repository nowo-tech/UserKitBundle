<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use DateTimeImmutable;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Sessions\EventListener\PasswordChangeListener;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Nowo\UserKitBundle\Sessions\PersonalData\SessionsEraser;
use Nowo\UserKitBundle\Sessions\PersonalData\SessionsExportProvider;
use Nowo\UserKitBundle\Sessions\Security\AccessGuard;
use Nowo\UserKitBundle\Sessions\Security\AllowAllSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Twig\SessionsTwigExtension;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Listener and store branches that a browser rarely reaches: a session that changed owner or id, an unstarted session
 * at login, a user without a registrable id, `_stateless` requests, duplicate inserts, the password change of another
 * user, the Twig helper behind a firewall, demo access.
 */
final class SessionEdgeCasesTest extends SessionsKernelTestCase
{
    public function testOwnerSwitchAndSessionIdMigration(): void
    {
        $this->boot(['doctrine' => ['entity_manager' => 'default']]);
        $alice    = $this->member('alice@example.com');
        $bob      = $this->member('bob@example.com');
        $listener = $this->listener();
        $tokens   = $this->tokens();

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = $this->request($session);
        $tokens->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        $listener->onRequest($this->event($request));
        self::assertCount(1, $this->rowsOf($alice));

        // Another user on the same session (no login event): the old row goes, a new one is registered.
        $tokens->setToken(new UsernamePasswordToken($bob, 'main', $bob->getRoles()));
        $listener->onRequest($this->event($request));
        self::assertCount(0, $this->rowsOf($alice));
        self::assertCount(1, $this->rowsOf($bob));

        // The session id migrated: the row follows it.
        $session->migrate();
        $listener->onRequest($this->event($request));
        self::assertSame($this->manager()->hash($session->getId()), $this->rowsOf($bob)[0]->getSessionHash());

        // `_stateless` routes are ignored.
        $stateless = $this->request($session);
        $stateless->attributes->set('_stateless', true);
        $listener->onRequest($this->event($stateless));
        $listener->onRequestEarly($this->event($stateless));
        self::assertCount(1, $this->rowsOf($bob));
    }

    public function testLoginSuccessEdgeCases(): void
    {
        $this->boot();
        $alice    = $this->member('alice@example.com');
        $listener = $this->listener();
        $auth     = $this->createMock(AuthenticatorInterface::class);
        $passport = new SelfValidatingPassport(new UserBadge('alice@example.com', static fn (): Member => $alice));
        $token    = new UsernamePasswordToken($alice, 'main', $alice->getRoles());

        // Not started yet: started, then registered.
        $session = new Session(new MockArraySessionStorage());
        $request = $this->request($session);
        $listener->onLoginSuccess(new LoginSuccessEvent($auth, $passport, $token, $request, null, 'main'));
        self::assertTrue($session->isStarted());
        self::assertCount(1, $this->rowsOf($alice));

        // A user without a registrable id (not persisted) is not registered.
        $ghost   = new Member('ghost@example.com', 'secret');
        $request = $this->request(new Session(new MockArraySessionStorage()));
        $listener->onLoginSuccess(new LoginSuccessEvent($auth, new SelfValidatingPassport(new UserBadge('ghost@example.com', static fn (): Member => $ghost)), new UsernamePasswordToken($ghost, 'main'), $request, null, 'main'));
        self::assertNull($this->manager()->owner($ghost));

        // `_stateless` and session-less requests are ignored.
        $stateless = $this->request(new Session(new MockArraySessionStorage()));
        $stateless->attributes->set('_stateless', true);
        $listener->onLoginSuccess(new LoginSuccessEvent($auth, $passport, $token, $stateless, null, 'main'));
        $listener->onLoginSuccess(new LoginSuccessEvent($auth, $passport, $token, new Request(), null, 'main'));
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testStoreEdgeCases(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $owner = $this->manager()->owner($alice) ?? self::fail();
        $now   = new DateTimeImmutable('2026-11-01 10:00:00');
        self::assertTrue($this->store()->insert($owner, 'h1', new SessionClient(), $now));
        self::assertFalse($this->store()->insert($owner, 'h1', new SessionClient(), $now));
        self::assertTrue($this->store()->insert($owner, 'h2', new SessionClient(), $now->modify('+1 minute')));
        self::assertCount(1, $this->store()->oldestActive($owner, $now->modify('-1 day'), 'h1', 5));
        self::assertSame(1, $this->store()->countActive($owner, $now->modify('-1 day'), 'h2'));
    }

    public function testPasswordChangeOfAnotherUserRevokesAllItsSessions(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $bob   = $this->member('bob@example.com');
        $this->signIn('bob@example.com');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($alice, 'main'));
        $listener = new PasswordChangeListener($this->manager(), 'password', $tokens, new RequestStack());
        $change   = ['password' => ['secret', 'changed']];
        $listener->preUpdate($bob, new PreUpdateEventArgs($bob, $this->em(), $change));
        $listener->postFlush();
        self::assertCount(0, $this->rowsOf($bob));

        $listener->preUpdate($bob, new PreUpdateEventArgs($bob, $this->em(), $change));
        $listener->reset();
        $listener->postFlush();
        $listener->preUpdate(new stdClass(), new PreUpdateEventArgs($bob, $this->em(), $change));
        $listener->postFlush();
        $this->addToAssertionCount(1);
    }

    public function testTwigHelperBehindAFirewallAndPersonalDataOfUnknownUsers(): void
    {
        $this->boot();
        $alice = $this->member('alice@example.com');
        $stack = new RequestStack();
        $main  = new Request();
        $main->attributes->set('_firewall_context', 'main');
        $stack->push($main);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($alice, 'main'));
        $urls = $this->container()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $profiles = $this->container()->get(ProfileRegistry::class);
        self::assertInstanceOf(ProfileRegistry::class, $profiles);

        $extension = new SessionsTwigExtension($this->manager(), $profiles, $urls, new AccessGuard(new AllowAllSessionsAccessChecker(), $tokens, $stack));
        self::assertSame('/admin/user-sessions/default/' . $alice->getId(), $extension->adminPath($alice));
        self::assertNull((new SessionsTwigExtension($this->manager(), $profiles, $urls, new AccessGuard(new AllowAllSessionsAccessChecker()), false))->adminPath($alice));

        self::assertSame([], (new SessionsExportProvider($this->manager(), $this->store()))->export(new stdClass()));
        self::assertFalse((new SessionsExportProvider($this->manager(), $this->store()))->supports(new stdClass()));
        $eraser = new SessionsEraser($this->manager(), $this->store());
        self::assertFalse($eraser->supports(new stdClass()));
        $eraser->erase(new ErasureRequest(new stdClass()));
        self::assertSame('sessions', $eraser->getKey());
    }

    public function testDemoAccessInDebug(): void
    {
        $this->boot(['security' => ['allow_unauthenticated' => true]], debug: true);
        $alice = $this->member('alice@example.com');
        $this->member('bob@example.com');
        $this->signIn('alice@example.com');
        $bob = $this->signIn('bob@example.com');
        $bob->request('GET', '/admin/user-sessions/default/' . $alice->getId());
        self::assertSame(200, $bob->getResponse()->getStatusCode());
    }

    private function listener(): SessionRegistryListener
    {
        $listener = $this->container()->get(SessionRegistryListener::class);
        self::assertInstanceOf(SessionRegistryListener::class, $listener);

        return $listener;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function request(Session $session): Request
    {
        $request = Request::create('/page');
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'cookie');

        return $request;
    }

    private function event(Request $request): RequestEvent
    {
        self::assertNotNull($this->kernel);

        return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
