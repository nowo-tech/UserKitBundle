<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Application\MySessions;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Domain\Account;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure\AccountSessionOwner;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure\AccountUserProvider;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure\CookieDeviceIdProvider;
use Nowo\UserKitBundle\Tests\Integration\DddHost\Infrastructure\SecurityAccount;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function assert;
use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * DDD / hexagonal host (REQ-ARCH-001): the account aggregate and the kit embeddables are mapped by **XML only** (the
 * bundle's `src/Resources/config/doctrine/model`), the security user is a read model resolved to the aggregate id by
 * a session owner resolver, the device id comes from the framework-free `DeviceIdProviderInterface`, and the
 * application service ({@see MySessions}) sees only ports and value objects. Controllers are the HTTP adapters.
 */
final class DddHostTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $id)
    {
        parent::__construct('test', false);
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-user-kit-ddd-host-tests';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new NowoUserKitBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    /**
     * HTTP adapter: builds the `SessionRef` at the edge and hands it to the application service.
     */
    public function mySessions(Request $request): JsonResponse
    {
        return new JsonResponse($this->app()->overview($this->user(), $this->manager()->sessionRef($request)));
    }

    public function signOutOthers(Request $request): JsonResponse
    {
        return new JsonResponse(['revoked' => $this->app()->signOutOthers($this->user(), $this->manager()->sessionRef($request))]);
    }

    public function login(): Response
    {
        return new Response('login');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router'                => ['utf8' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/ddd.db'],
            'orm'  => ['mappings' => [
                'DddHost' => [
                    'type'      => 'xml',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/config/doctrine',
                    'prefix'    => __NAMESPACE__ . '\Domain',
                ],
                // The opt-in DDD mapping of the kit embeddables, as documented in docs/USAGE.md.
                'NowoUserKitModel' => [
                    'type'      => 'xml',
                    'is_bundle' => false,
                    'dir'       => dirname(__DIR__, 3) . '/src/Resources/config/doctrine/model',
                    'prefix'    => 'Nowo\UserKitBundle\Model',
                ],
            ]],
        ]);
        $container->extension('security', [
            'password_hashers' => [SecurityAccount::class => 'plaintext'],
            'providers'        => ['accounts' => ['id' => AccountUserProvider::class]],
            'firewalls'        => ['main' => [
                'lazy'       => true,
                'provider'   => 'accounts',
                'form_login' => ['login_path' => '/login', 'check_path' => '/login', 'enable_csrf' => false, 'default_target_path' => '/me'],
                'logout'     => ['path' => '/logout'],
            ]],
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/me', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->extension('nowo_user_kit', [
            'user_class' => Account::class,
            'sessions'   => [
                'enabled' => true,
                'owner'   => ['resolver' => AccountSessionOwner::class, 'on_unresolved' => 'exception'],
                'client'  => ['device_id_resolver' => CookieDeviceIdProvider::class],
            ],
        ]);
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(AccountUserProvider::class)->args([service('doctrine')]);
        $services->set(AccountSessionOwner::class);
        $services->set(CookieDeviceIdProvider::class);
        $services->set(MySessions::class)->autowire()->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('login', '/login')->controller('kernel::login');
        $routes->add('me', '/me')->controller('kernel::mySessions');
        $routes->add('me_sign_out_others', '/me/sign-out-others')->controller('kernel::signOutOthers')->methods(['POST']);
    }

    private function app(): MySessions
    {
        $app = $this->testContainer()->get(MySessions::class);
        assert($app instanceof MySessions);

        return $app;
    }

    private function manager(): UserSessionManager
    {
        $manager = $this->testContainer()->get(UserSessionManager::class);
        assert($manager instanceof UserSessionManager);

        return $manager;
    }

    private function user(): SecurityAccount
    {
        $tokens = $this->testContainer()->get('security.token_storage');
        assert($tokens instanceof TokenStorageInterface);
        $user = $tokens->getToken()?->getUser();
        assert($user instanceof SecurityAccount);

        return $user;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }
}
