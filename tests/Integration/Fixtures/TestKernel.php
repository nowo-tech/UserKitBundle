<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\Session\SessionInvalidatorInterface;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sprintf;

/**
 * Minimal application kernel: FrameworkBundle + SecurityBundle + DoctrineBundle (SQLite in memory) + UserKit.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $userKitConfig
     */
    public function __construct(
        private readonly array $userKitConfig = [],
        private readonly bool $appUserChecker = false,
    ) {
        parent::__construct('test', false);
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
        return sprintf('%s/nowo-user-kit-tests/%s/cache', sys_get_temp_dir(), $this->variant());
    }

    public function getLogDir(): string
    {
        return sprintf('%s/nowo-user-kit-tests/%s/log', sys_get_temp_dir(), $this->variant());
    }

    public function home(): Response
    {
        return new Response('ok');
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret'               => 'test',
                'test'                 => true,
                'http_method_override' => false,
                'router'               => ['utf8' => true],
                'php_errors'           => ['log' => true],
            ]);

            $mainFirewall = ['pattern' => '^/', 'http_basic' => null, 'provider' => 'app', 'stateless' => true];
            if ($this->appUserChecker) {
                $mainFirewall['user_checker'] = AppUserChecker::class;
            }

            $container->loadFromExtension('security', [
                'password_hashers' => [User::class => 'plaintext'],
                'providers'        => ['app' => ['entity' => ['class' => User::class, 'property' => 'username']]],
                'firewalls'        => [
                    'public' => ['pattern' => '^/public', 'security' => false],
                    'main'   => $mainFirewall,
                ],
                'access_control' => [['path' => '^/', 'roles' => 'ROLE_USER']],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm'  => [
                    'mappings' => [
                        'UserKitTest' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/Entity',
                            'prefix'    => 'Nowo\\UserKitBundle\\Tests\\Integration\\Fixtures\\Entity',
                        ],
                        // Ready-made embeddables (UiPreferences, ProductTourProgress), as documented in USAGE.md.
                        'NowoUserKitModel' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => dirname(__DIR__, 3) . '/src/Model',
                            'prefix'    => 'Nowo\\UserKitBundle\\Model',
                        ],
                    ],
                ],
            ]);

            $container->loadFromExtension('nowo_user_kit', array_replace(['user_class' => User::class], $this->userKitConfig));

            $container->register(AppUserChecker::class, AppUserChecker::class)->setPublic(true);
            $container->register(SpySessionInvalidator::class, SpySessionInvalidator::class)->setPublic(true);
            $container->setAlias(SessionInvalidatorInterface::class, SpySessionInvalidator::class);
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('home', '/')->controller('kernel::home');
        $routes->add('public', '/public')->controller('kernel::home');
    }

    private function variant(): string
    {
        return md5(serialize([$this->userKitConfig, $this->appUserChecker]));
    }
}
