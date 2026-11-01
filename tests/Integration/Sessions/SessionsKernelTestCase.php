<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Sessions\Doctrine\UserSessionStore;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionsTestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function md5;
use function serialize;
use function substr;

/**
 * Boots {@see SessionsTestKernel} (fresh SQLite schema), creates members and signs browsers in through form_login.
 */
abstract class SessionsKernelTestCase extends TestCase
{
    protected ?SessionsTestKernel $kernel = null;

    protected function tearDown(): void
    {
        if ($this->kernel instanceof SessionsTestKernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
        (new Filesystem())->remove(SessionsTestKernel::baseDir());
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $sessions `nowo_user_kit.sessions` (merged over enabled + pages)
     * @param array<string, mixed> $userKit other `nowo_user_kit` keys
     * @param array{remember_me?: array<string, mixed>, back_firewall?: bool} $options test kernel options
     * @param array{remember_me?: array<string, mixed>, back_firewall?: bool} $options test kernel options
     */
    protected function boot(array $sessions = [], array $userKit = [], bool $debug = false, ?Closure $services = null, ?Closure $routes = null, array $options = []): SessionsTestKernel
    {
        $config       = $userKit + ['sessions' => array_replace_recursive(['enabled' => true, 'web_ui' => ['enabled' => true]], $sessions)];
        $this->kernel = new SessionsTestKernel(md5(serialize([$config, $debug, $options, static::class, $this->name()])), $config, $routes, [], $debug, $services, $options);
        $this->kernel->boot();
        $em = $this->em();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Member::class), $em->getClassMetadata(UserSession::class)]);

        return $this->kernel;
    }

    protected function container(): ContainerInterface
    {
        self::assertInstanceOf(SessionsTestKernel::class, $this->kernel);

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    protected function em(): EntityManagerInterface
    {
        $em = $this->container()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    protected function manager(): UserSessionManager
    {
        $manager = $this->container()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $manager);

        return $manager;
    }

    protected function store(): UserSessionStore
    {
        $store = $this->container()->get(UserSessionStore::class);
        self::assertInstanceOf(UserSessionStore::class, $store);

        return $store;
    }

    /**
     * @param list<string> $roles
     */
    protected function member(string $email, array $roles = ['ROLE_USER']): Member
    {
        $member = new Member($email, 'secret', $roles);
        $this->em()->persist($member);
        $this->em()->flush();

        return $member;
    }

    protected function browser(): KernelBrowser
    {
        self::assertInstanceOf(SessionsTestKernel::class, $this->kernel);
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();
        $browser->catchExceptions(true);

        return $browser;
    }

    /**
     * Signs a new browser in (form_login) and follows to the landing page.
     */
    protected function signIn(string $email, string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0', bool $remember = false): KernelBrowser
    {
        $browser = $this->browser();
        $browser->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $browser->request('POST', '/login', ['_username' => $email, '_password' => 'secret'] + ($remember ? ['_remember_me' => 'on'] : []));

        return $browser;
    }

    /**
     * Live rows of a member.
     *
     * @phpstan-impure
     *
     * @return list<UserSession>
     */
    protected function rowsOf(Member $member): array
    {
        $this->em()->clear();

        return $this->manager()->listFor($member);
    }

    protected function clock(): MockClock
    {
        $clock = $this->container()->get(MockClock::class);
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    /**
     * @param non-empty-string $path
     */
    protected static function assertRedirectsTo(KernelBrowser $browser, string $path): void
    {
        $response = $browser->getResponse();
        self::assertTrue($response->isRedirect(), 'Expected a redirect, got ' . $response->getStatusCode() . ': ' . substr((string) $response->getContent(), 0, 300));
        self::assertStringEndsWith($path, (string) $response->headers->get('Location'));
    }

    protected function body(KernelBrowser $browser): string
    {
        return (string) $browser->getResponse()->getContent();
    }
}
