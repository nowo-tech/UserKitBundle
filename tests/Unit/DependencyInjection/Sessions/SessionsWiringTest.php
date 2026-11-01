<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection\Sessions;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UserKitBundle\DependencyInjection\Compiler\SessionsPass;
use Nowo\UserKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\DependencyInjection\SessionsLoader;
use Nowo\UserKitBundle\Session\DefaultSessionInvalidator;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Sessions\Controller\SessionsController;
use Nowo\UserKitBundle\Sessions\EventListener\SessionRegistryListener;
use Nowo\UserKitBundle\Sessions\RegistrySessionInvalidator;
use Nowo\UserKitBundle\Sessions\Security\AllowAllSessionsAccessChecker;
use Nowo\UserKitBundle\Sessions\Security\SessionsAccessCheckerInterface;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Support\FakeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

final class SessionsWiringTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $container = $this->load([]);
        self::assertFalse($container->hasDefinition(UserSessionManager::class));
        self::assertFalse($container->getParameter('nowo_user_kit.sessions.enabled'));
    }

    public function testCustomCheckerAndDemoChecker(): void
    {
        $custom = $this->load(['enabled' => true, 'web_ui' => ['enabled' => true], 'security' => ['access_checker' => 'app.checker']]);
        self::assertSame('app.checker', (string) $custom->getAlias(SessionsAccessCheckerInterface::class));

        $demo = $this->load(['enabled' => true, 'web_ui' => ['enabled' => true], 'security' => ['allow_unauthenticated' => true]]);
        self::assertSame(AllowAllSessionsAccessChecker::class, (string) $demo->getAlias(SessionsAccessCheckerInterface::class));
    }

    public function testSwitchesMustBeLiteralBooleans(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_user_kit.sessions.enabled must be a literal boolean');
        SessionsLoader::load(new ContainerBuilder(), ['enabled' => '%env(bool:X)%', 'web_ui' => ['enabled' => false, 'admin' => true], 'security' => ['allow_unauthenticated' => false]], [], false);
    }

    public function testPrependMapsTheEntityAndTheFormKitProfile(): void
    {
        $container = $this->builder();
        $container->registerExtension(new FakeExtension('doctrine'));
        $container->registerExtension(new FakeExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_user_kit', ['sessions' => ['enabled' => true, 'web_ui' => ['enabled' => true], 'doctrine' => ['entity_manager' => 'sessions']]]);
        SessionsLoader::prepend($container);

        $doctrine = $container->getExtensionConfig('doctrine')[0];
        self::assertArrayHasKey('NowoUserKitSessions', $doctrine['orm']['entity_managers']['sessions']['mappings']);
        self::assertArrayHasKey(SessionsLoader::FORM_KIT_PROFILE, $container->getExtensionConfig('nowo_form_kit')[0]['profiles']);
    }

    public function testPrependKeepsAHostFormKitProfileAndSkipsWhenDisabled(): void
    {
        $container = $this->builder();
        $container->registerExtension(new FakeExtension('doctrine'));
        $container->registerExtension(new FakeExtension('nowo_form_kit'));
        $container->loadFromExtension('nowo_form_kit', ['profiles' => [SessionsLoader::FORM_KIT_PROFILE => ['translation_domain' => 'app']]]);
        $container->loadFromExtension('nowo_user_kit', ['sessions' => ['enabled' => true, 'web_ui' => ['enabled' => true]]]);
        SessionsLoader::prepend($container);
        self::assertCount(1, $container->getExtensionConfig('nowo_form_kit'));
        self::assertArrayHasKey('mappings', $container->getExtensionConfig('doctrine')[0]['orm']);

        $off = $this->builder();
        $off->registerExtension(new FakeExtension('doctrine'));
        SessionsLoader::prepend($off);
        self::assertSame([], $off->getExtensionConfig('doctrine'));
    }

    public function testPassRequiresSecurityAndDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(UserSessionManager::class, new Definition(UserSessionManager::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires SecurityBundle and DoctrineBundle');
        (new SessionsPass())->process($container);
    }

    public function testPassAliasesTheInvalidatorUnlessTheHostDid(): void
    {
        $container = $this->passContainer(false);
        $container->setAlias(SessionInvalidatorInterface::class, DefaultSessionInvalidator::class);
        (new SessionsPass())->process($container);
        self::assertSame(RegistrySessionInvalidator::class, (string) $container->getAlias(SessionInvalidatorInterface::class));

        $host = $this->passContainer(false);
        $host->setAlias(SessionInvalidatorInterface::class, 'app.invalidator');
        (new SessionsPass())->process($host);
        self::assertSame('app.invalidator', (string) $host->getAlias(SessionInvalidatorInterface::class));
    }

    public function testPassFailsClosedWithoutCsrf(): void
    {
        $container = $this->passContainer(true);
        $container->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('require CSRF protection');
        (new SessionsPass())->process($container);
    }

    public function testPassRequiresTwigAndFormKit(): void
    {
        $container = $this->passContainer(true);
        $container->removeDefinition('twig');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('require TwigBundle');
        (new SessionsPass())->process($container);
    }

    public function testPassRefusesDemoAccessOutsideDebug(): void
    {
        $container = $this->passContainer(true);
        $container->setParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER, true);
        $container->setParameter('kernel.debug', false);
        $this->expectException(InvalidConfigurationException::class);
        (new SessionsPass())->process($container);
    }

    public function testPassAcceptsDemoAccessInDebug(): void
    {
        $container = $this->passContainer(true);
        $container->setParameter(SessionsLoader::ALLOW_UNAUTHENTICATED_PARAMETER, true);
        $container->setParameter('kernel.debug', true);
        (new SessionsPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testPassRefusesPerServerCachePools(): void
    {
        $container = $this->passContainer(false);
        $container->setParameter(SessionsLoader::CACHE_POOL_PARAMETER, 'app.pool');
        $container->setDefinition('cache.adapter.filesystem', new Definition(FilesystemAdapter::class));
        $container->setDefinition('cache.app', new ChildDefinition('cache.adapter.filesystem'));
        $container->setAlias('app.pool', 'cache.app');
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('per-server pool');
        (new SessionsPass())->process($container);
    }

    public function testPassAcceptsSharedOrUnknownPools(): void
    {
        foreach ([
            'redis'    => [new ChildDefinition('cache.adapter.redis'), new Definition(RedisAdapter::class)],
            'orphan'   => [new ChildDefinition('missing.parent'), null],
            'factory'  => [(new Definition())->setFactory(['App\\Pools', 'create']), null],
            'resolved' => [new Definition('%app.pool.class%'), null],
        ] as $id => [$pool, $parent]) {
            $container = $this->passContainer(false);
            $container->setParameter(SessionsLoader::CACHE_POOL_PARAMETER, $id);
            if ($parent instanceof Definition) {
                $container->setDefinition('cache.adapter.redis', $parent);
            }
            $container->setDefinition($id, $pool);
            (new SessionsPass())->process($container);
        }
        $this->addToAssertionCount(1);
    }

    public function testPassHandsTheFirewallRememberMeCookiesToTheListener(): void
    {
        $container = $this->passContainer(false);
        $container->setDefinition(SessionRegistryListener::class, new Definition(SessionRegistryListener::class, array_fill(0, 12, null)))
            ->replaceArgument(10, SessionsLoader::extraCookies(['LEGACY', 'REMEMBERME']));
        $handler = new ChildDefinition('security.authenticator.signature_remember_me_handler');
        $handler->replaceArgument(3, ['name' => 'APP_REMEMBER', 'path' => '/app', 'domain' => '.example.com', 'secure' => true, 'httponly' => true, 'samesite' => 'strict']);
        $container->setDefinition('security.authenticator.remember_me_handler.main', $handler->addTag('security.remember_me_handler', ['firewall' => 'main']));
        $default = new Definition('stdClass', [null, null, null, ['name' => 'REMEMBERME', 'path' => '/']]);
        $container->setDefinition('security.authenticator.remember_me_handler.admin', $default->addTag('security.remember_me_handler', ['firewall' => 'admin']));
        $container->setDefinition('security.authenticator.remember_me_handler.odd', (new Definition('stdClass'))->addTag('security.remember_me_handler', ['firewall' => 'odd']));
        (new SessionsPass())->process($container);

        $cookies = $container->getDefinition(SessionRegistryListener::class)->getArgument(10);
        self::assertSame(['LEGACY', 'REMEMBERME', 'APP_REMEMBER'], array_column($cookies, 'name'));
        self::assertSame(['name' => 'APP_REMEMBER', 'path' => '/app', 'domain' => '.example.com', 'secure' => true, 'httponly' => true, 'samesite' => 'strict'], $cookies[2]);
    }

    public function testTwigPathsPass(): void
    {
        $project = sys_get_temp_dir() . '/nowo-user-kit-twig-paths';
        (new Filesystem())->mkdir($project . '/templates/bundles/NowoUserKitBundle');
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $project);
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');
        (new TwigPathsPass())->process($container);
        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        (new Filesystem())->remove($project);

        self::assertSame('prependPath', $calls[0][0]);
        self::assertSame('addPath', $calls[1][0]);

        $none = new ContainerBuilder();
        (new TwigPathsPass())->process($none);
        self::assertFalse($none->hasDefinition('twig.loader.native_filesystem'));
    }

    private function passContainer(bool $pages): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ([UserSessionManager::class, 'security.token_storage', 'doctrine', 'security.csrf.token_manager', 'form.type_extension.csrf', 'twig', CsrfOnlyFormFactory::class] as $id) {
            $container->setDefinition($id, new Definition('stdClass'));
        }
        if ($pages) {
            $container->setDefinition(SessionsController::class, new Definition(SessionsController::class));
        }

        return $container;
    }

    private function builder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoUserKitExtension());

        return $container;
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
}
