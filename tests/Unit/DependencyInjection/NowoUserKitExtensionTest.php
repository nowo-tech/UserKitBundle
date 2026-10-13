<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\EventListener\AccountDisabledListener;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Security\AccountStatusUserChecker;
use Nowo\UserKitBundle\Twig\UiPreferencesExtension;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\DependencyInjection\MergeExtensionConfigurationPass;

final class NowoUserKitExtensionTest extends TestCase
{
    public function testLoadRegistersServices(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class'    => 'App\\Entity\\User',
            'last_activity' => ['enabled' => true],
        ]], $container);

        $this->assertSame('App\\Entity\\User', $container->getParameter('nowo_user_kit.user_class'));
        $this->assertSame('default', $container->getParameter('nowo_user_kit.default_profile'));
        $this->assertTrue($container->hasDefinition(AccountStatusUserChecker::class));
        $this->assertTrue($container->hasDefinition(LastActivitySubscriber::class));
        $this->assertTrue($container->hasDefinition(ProfileRegistry::class));
        $this->assertFalse($container->hasDefinition(AccountDisabledListener::class));
    }

    public function testUsesAuthKitUserClassBridge(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.user_class', 'App\\Entity\\AuthUser');

        (new NowoUserKitExtension())->load([[]], $container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_user_kit.user_class'));
    }

    /**
     * @param array<string, mixed> $authKitConfig
     */
    #[DataProvider('authKitConfigProvider')]
    public function testPrependResolvesAuthKitUserClassFromRawConfig(array $authKitConfig): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_auth_kit', $authKitConfig);

        $extension = new NowoUserKitExtension();
        $extension->prepend($container);
        $extension->load([[]], $container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_user_kit.user_class'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authKitConfigProvider(): iterable
    {
        yield 'legacy flat user_class' => [['user_class' => 'App\\Entity\\AuthUser']];
        yield 'first profile' => [['profiles' => ['main' => ['user_class' => 'App\\Entity\\AuthUser'], 'other' => ['user_class' => 'App\\Entity\\Other']]]];
        yield 'explicit default_profile' => [['default_profile' => 'admin', 'profiles' => ['main' => ['user_class' => 'App\\Entity\\Other'], 'admin' => ['user_class' => 'App\\Entity\\AuthUser']]]];
    }

    public function testPrependUsesLastAuthKitFileThatDefinesUserClass(): void
    {
        $container = new ContainerBuilder();
        // prependExtensionConfig() inserts at the front: the later file is added first.
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['main' => ['user_class' => 'App\\Entity\\AuthUser']]]);
        $container->prependExtensionConfig('nowo_auth_kit', ['user_class' => 'App\\Entity\\Old']);
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['main' => []]]);

        $extension = new NowoUserKitExtension();
        $extension->prepend($container);
        $extension->load([[]], $container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_user_kit.user_class'));
    }

    public function testPrependIgnoresAuthKitConfigWithoutUserClass(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_auth_kit', ['default_profile' => 'missing', 'profiles' => ['main' => ['user_class' => 'App\\Entity\\AuthUser']]]);
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => []]);

        $extension = new NowoUserKitExtension();
        $extension->prepend($container);

        $this->expectException(InvalidArgumentException::class);
        $extension->load([[]], $container);
    }

    public function testExplicitUserClassWinsOverAuthKit(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_auth_kit', ['user_class' => 'App\\Entity\\AuthUser']);

        $extension = new NowoUserKitExtension();
        $extension->prepend($container);
        $extension->load([['user_class' => 'App\\Entity\\User']], $container);

        $this->assertSame('App\\Entity\\User', $container->getParameter('nowo_user_kit.user_class'));
    }

    #[DataProvider('bundleOrderProvider')]
    public function testAuthKitBridgeDoesNotDependOnBundleOrder(bool $userKitFirst): void
    {
        // Stand-in for AuthKitBundle's extension: it only defines its parameter in load().
        $authKit = new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
                $container->setParameter('nowo_auth_kit.user_class', $configs[0]['user_class'] ?? null);
            }

            public function getAlias(): string
            {
                return 'nowo_auth_kit';
            }
        };
        $userKit = new NowoUserKitExtension();

        $container = new ContainerBuilder();
        foreach ($userKitFirst ? [$userKit, $authKit] : [$authKit, $userKit] as $extension) {
            $container->registerExtension($extension);
        }
        $container->loadFromExtension('nowo_auth_kit', ['user_class' => 'App\\Entity\\AuthUser']);
        $container->loadFromExtension('nowo_user_kit', []);

        (new MergeExtensionConfigurationPass(['nowo_auth_kit', 'nowo_user_kit']))->process($container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_user_kit.user_class'));

        $container->getCompilerPassConfig()->setMergePass(new MergeExtensionConfigurationPass([]));
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->compile();
        $this->assertFalse($container->hasParameter('.nowo_user_kit.auth_kit_user_class'), 'Build-time parameter must not leak into the compiled container.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function bundleOrderProvider(): iterable
    {
        yield 'UserKit registered before AuthKit' => [true];
        yield 'AuthKit registered before UserKit' => [false];
    }

    public function testMissingUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NowoUserKitExtension())->load([[]], new ContainerBuilder());
    }

    public function testTwigDisabledWhenRequested(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'twig'       => false,
        ]], $container);

        $this->assertFalse($container->hasDefinition(UiPreferencesExtension::class));
        $this->assertFalse($container->hasDefinition(UserPresenceExtension::class));
    }

    public function testProfilesConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([[
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => [
                    'user_class'     => 'App\\Entity\\User',
                    'last_activity'  => ['enabled' => true],
                    'account_status' => ['enabled' => false],
                ],
                'admin' => [
                    'user_class'     => 'App\\Entity\\Admin',
                    'last_activity'  => ['enabled' => false],
                    'account_status' => ['enabled' => true],
                ],
            ],
        ]], $container);

        $this->assertSame('App\\Entity\\Admin', $container->getParameter('nowo_user_kit.user_class'));
        $this->assertTrue($container->hasDefinition(AccountStatusUserChecker::class));
        $this->assertTrue($container->hasDefinition(LastActivitySubscriber::class));
    }

    public function testDuplicateUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate user_class');

        (new NowoUserKitExtension())->load([[
            'profiles' => [
                'one' => ['user_class' => 'App\\Entity\\User'],
                'two' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingDefaultProfileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoUserKitExtension())->load([[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingProfileUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoUserKitExtension())->load([[
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
                'admin'   => ['user_class' => ''],
            ],
        ]], new ContainerBuilder());
    }
}
