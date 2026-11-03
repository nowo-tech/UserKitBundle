<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Security\PendingAuthentication;
use Nowo\UserKitBundle\Security\PendingAuthenticationTokenInterface;
use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\Model\SessionStatus;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\PendingFlow;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * 1.11.0 (spec 006): a sign-in whose token is pending (second factor) is registered only once the token is final,
 * concurrent-session limit included.
 */
final class PendingSignInTest extends SessionsKernelTestCase
{
    /** @var list<object> */
    private array $events = [];

    public function testAPendingSignInIsRegisteredOnceItsTokenIsFinal(): void
    {
        $this->bootPending();
        $alice = $this->member('alice@example.com');
        $phone = $this->pendingSignIn('alice@example.com', 'interface');
        self::assertSame([], $this->rowsOf($alice), 'Nothing is registered while the second factor is pending.');

        $phone->request('GET', '/page');
        self::assertSame(403, $phone->getResponse()->getStatusCode(), 'A pending token has no roles.');
        self::assertSame([], $this->rowsOf($alice), 'Nothing is touched while pending.');

        $phone->request('GET', '/complete');
        self::assertSame([], $this->rowsOf($alice), 'The completing request itself still ran as pending.');
        $phone->request('GET', '/page');
        self::assertTrue($phone->getResponse()->isSuccessful());
        self::assertCount(1, $this->rowsOf($alice));
        $started = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof UserSessionStartedEvent));
        self::assertCount(1, $started);
        self::assertTrue($started[0]->atLogin, 'Registered as a sign-in.');
        self::assertTrue($this->manager()->sessionState((string) $phone->getCookieJar()->get('MOCKSESSID')?->getValue(), $alice)->isLive());
    }

    public function testThePendingAttributeWorksWithoutTheInterface(): void
    {
        $this->bootPending();
        $alice = $this->member('alice@example.com');
        $phone = $this->pendingSignIn('alice@example.com', 'attribute');
        self::assertSame([], $this->rowsOf($alice));

        $phone->request('GET', '/complete');
        $phone->request('GET', '/page');
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testTheLimitAppliesWhenThePendingSignInCompletes(): void
    {
        $this->bootPending(['limit' => ['max_sessions' => 1, 'policy' => 'evict_oldest']]);
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->pendingSignIn('alice@example.com', 'interface');
        $sid    = (string) $laptop->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertTrue($this->manager()->sessionState($sid, $alice)->isLive(), 'A pending sign-in evicts nobody.');

        $phone->request('GET', '/complete');
        $phone->request('GET', '/page');
        self::assertSame(SessionStatus::Revoked, $this->manager()->sessionState($sid, $alice)->status);
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testAFinalSignInInTheSameBrowserClearsThePendingFlag(): void
    {
        $this->bootPending(['limit' => ['max_sessions' => 2, 'policy' => 'evict_oldest']]);
        $alice  = $this->member('alice@example.com');
        $laptop = $this->signIn('alice@example.com');
        $phone  = $this->pendingSignIn('alice@example.com', 'interface');
        $phone->request('POST', '/login', ['_username' => 'alice@example.com', '_password' => 'secret']);
        self::assertCount(2, $this->rowsOf($alice));

        $phone->request('GET', '/page');
        $phone->request('GET', '/page');
        self::assertCount(2, $this->rowsOf($alice), 'The final sign-in was not registered a second time.');
        self::assertSame([], array_filter($this->events, static fn (object $e): bool => $e instanceof SessionLimitReachedEvent));
        $laptop->request('GET', '/page');
        self::assertTrue($laptop->getResponse()->isSuccessful());
    }

    public function testPendingFlagsOfOtherContextsAreKept(): void
    {
        $this->bootPending();
        $alice                                             = $this->member('alice@example.com');
        $phone                                             = $this->pendingSignIn('alice@example.com', 'interface');
        $session                                           = $this->sessionOf($phone);
        $session['_nowo_user_kit_session_pending']['back'] = true;
        $session['_nowo_user_kit_session_pending'][7]      = true;
        $session['_nowo_user_kit_session_pending']['x']    = 'yes';
        $this->writeSession($phone, $session);

        $phone->request('GET', '/complete');
        $phone->request('GET', '/page');
        self::assertCount(1, $this->rowsOf($alice));
        self::assertSame(['back' => true], $this->sessionOf($phone)[SessionRegistryListener::PENDING_ATTRIBUTE]);
    }

    public function testPendingDetection(): void
    {
        $user  = new InMemoryUser('a', null);
        $token = new UsernamePasswordToken($user, 'main');
        self::assertFalse(PendingAuthentication::isPending(null));
        self::assertFalse(PendingAuthentication::isPending(new NullToken()));
        self::assertFalse(PendingAuthentication::isPending($token));
        $token->setAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE, 'yes');
        self::assertFalse(PendingAuthentication::isPending($token));
        $token->setAttribute(PendingAuthenticationTokenInterface::ATTRIBUTE, true);
        self::assertTrue(PendingAuthentication::isPending($token));
    }

    /**
     * @param array<string, mixed> $sessions
     */
    private function bootPending(array $sessions = []): void
    {
        $this->boot(
            $sessions,
            services: static function (ContainerConfigurator $c): void {
                $c->services()->set(PendingFlow::class)->autowire()->public()
                    ->tag('kernel.event_listener', ['event' => AuthenticationTokenCreatedEvent::class, 'method' => 'onTokenCreated', 'dispatcher' => 'security.event_dispatcher.main']);
            },
            routes: static function (RoutingConfigurator $routes): void {
                $routes->add('page', '/page')->controller('kernel::page');
                $routes->add('login', '/login')->controller('kernel::login');
                $routes->add('complete', '/complete')->controller(PendingFlow::class . '::complete');
                $routes->add('logout', '/logout');
            },
        );
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(UserSessionStartedEvent::class, fn (object $e): object => $this->events[] = $e);
        $dispatcher->addListener(SessionLimitReachedEvent::class, fn (object $e): object => $this->events[] = $e);
    }

    private function pendingSignIn(string $email, string $mode): KernelBrowser
    {
        $browser = $this->browser();
        $browser->request('POST', '/login', ['_username' => $email, '_password' => 'secret', '_pending' => $mode]);

        return $browser;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionOf(KernelBrowser $browser): array
    {
        $data = unserialize((string) file_get_contents($this->sessionFile($browser)));
        self::assertIsArray($data);

        return $data['_sf2_attributes'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function writeSession(KernelBrowser $browser, array $attributes): void
    {
        $data                    = unserialize((string) file_get_contents($this->sessionFile($browser)));
        $data['_sf2_attributes'] = $attributes;
        file_put_contents($this->sessionFile($browser), serialize($data));
    }

    private function sessionFile(KernelBrowser $browser): string
    {
        $file = $this->kernel?->getCacheDir() . '/sessions/' . $browser->getCookieJar()->get('MOCKSESSID')?->getValue() . '.mocksess';
        self::assertFileExists($file);

        return $file;
    }
}
