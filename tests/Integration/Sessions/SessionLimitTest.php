<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Event\SessionLimitReachedEvent;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\RoleLimitResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Concurrent-session limit: evict oldest, reject new (bundle message, custom message), host resolver.
 */
final class SessionLimitTest extends SessionsKernelTestCase
{
    public function testEvictOldestSignsTheLeastRecentlyActiveSessionOut(): void
    {
        $this->boot(['limit' => ['max_sessions' => 2]]);
        $alice  = $this->member('alice@example.com');
        $events = $this->listen();
        $first  = $this->signIn('alice@example.com');
        $this->clock()->sleep(10);
        $second = $this->signIn('alice@example.com');
        $this->clock()->sleep(10);
        $third = $this->signIn('alice@example.com');
        $third->request('GET', '/page');

        self::assertCount(1, $events);
        self::assertFalse($events->first()->isRejected());
        self::assertSame(2, $events->first()->activeSessions);
        self::assertCount(1, $events->first()->evicted);
        self::assertCount(2, $this->rowsOf($alice));

        $first->request('GET', '/page');
        self::assertRedirectsTo($first, '/login');
        $first->request('GET', '/login');
        $second->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($second));
    }

    public function testRejectNewRefusesTheSignIn(): void
    {
        $this->boot(['limit' => ['max_sessions' => 1, 'policy' => 'reject_new']]);
        $alice  = $this->member('alice@example.com');
        $events = $this->listen();
        $first  = $this->signIn('alice@example.com');
        $second = $this->signIn('alice@example.com');
        self::assertRedirectsTo($second, '/login');
        $second->request('GET', '/login');
        self::assertStringContainsString('You are already signed in on 1 devices', $this->body($second));
        self::assertCount(1, $events);
        self::assertTrue($events->first()->isRejected());
        self::assertNull($events->first()->getMessage());

        $first->request('GET', '/page');
        self::assertStringStartsWith('alice@example.com', $this->body($first));
        self::assertCount(1, $this->rowsOf($alice));

        // Signing in again from the same (registered) browser replaces its own session.
        $first->request('POST', '/login', ['_username' => 'alice@example.com', '_password' => 'secret']);
        self::assertRedirectsTo($first, '/page');
        self::assertCount(1, $this->rowsOf($alice));
    }

    public function testAListenerCanReplaceTheMessage(): void
    {
        $this->boot(['limit' => ['max_sessions' => 1, 'policy' => 'reject_new']]);
        $this->member('alice@example.com');
        $this->dispatcher()->addListener(SessionLimitReachedEvent::class, static function (SessionLimitReachedEvent $event): void {
            $event->setMessage('Check your e-mail to sign the other device out.');
        });
        $this->signIn('alice@example.com');
        $second = $this->signIn('alice@example.com');
        $second->request('GET', '/login');
        self::assertStringContainsString('Check your e-mail', $this->body($second));
    }

    public function testHostResolver(): void
    {
        $this->boot(['limit' => ['resolver' => RoleLimitResolver::class]], services: static function (ContainerConfigurator $c): void {
            $c->services()->set(RoleLimitResolver::class);
        });
        $this->member('root@example.com', ['ROLE_ADMIN']);
        $this->member('alice@example.com');
        $this->signIn('root@example.com');
        $admin = $this->signIn('root@example.com');
        self::assertRedirectsTo($admin, '/page');
        $this->signIn('alice@example.com');
        self::assertRedirectsTo($this->signIn('alice@example.com'), '/login');
    }

    private function listen(): EventRecorder
    {
        $events = new EventRecorder();
        $this->dispatcher()->addListener(SessionLimitReachedEvent::class, $events->record(...));

        return $events;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
