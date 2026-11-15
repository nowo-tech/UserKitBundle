<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Nowo\UserKitBundle\DependencyInjection\Configuration;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\DependencyInjection\OwnerImagesLoader;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;

/**
 * 1.18 `nowo_user_kit.owner_images`: off by default, kinds validated, the avatar kind is a user kind, the wiring.
 */
final class OwnerImageConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $ownerImages
     *
     * @return array<string, mixed>
     */
    private function process(array $ownerImages): array
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['owner_images' => $ownerImages]]);

        return $config['owner_images'];
    }

    public function testDefaults(): void
    {
        $config = $this->process([]);
        self::assertFalse($config['enabled']);
        self::assertSame(['avatar' => ['subject' => 'user', 'read' => 'authenticated', 'shape' => 'circle']], $config['kinds']);
        self::assertSame('avatar', $config['avatar_kind']);
        self::assertSame([null, null, null, null], [$config['access'], $config['pointer_store'], $config['storage'], $config['doctrine']['entity_manager']]);
        self::assertSame(OwnerImageExtension::SIZES, $config['twig']['sizes']);
    }

    public function testKindsAreValidated(): void
    {
        foreach ([['Bad Name' => []], []] as $kinds) {
            try {
                $this->process(['kinds' => $kinds]);
                self::fail('accepted');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('at least one kind', $e->getMessage());
            }
        }
        $config = $this->process(['kinds' => ['org_logo' => []], 'avatar_kind' => 'avatar']);
        self::assertSame(['subject' => 'other', 'read' => 'authenticated', 'shape' => 'square'], $config['kinds']['org_logo']);
    }

    public function testTheAvatarKindMustBeAUserKind(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('avatar_kind');
        $this->process(['kinds' => ['org_logo' => []], 'avatar_kind' => 'org_logo']);
    }

    public function testDisabledRegistersNothing(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);
        self::assertFalse($container->getParameter('nowo_user_kit.owner_images.enabled'));
        self::assertFalse($container->has(OwnerImagesInterface::class));
    }

    public function testTheDefaultStorageNeedsStorageKit(): void
    {
        OwnerImagesLoader::assertStorageKit(true);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('storage-kit-bundle 1.6+');
        OwnerImagesLoader::assertStorageKit(false);
    }

    public function testThePointerMappingIsPrependedOnlyForTheDefaultStore(): void
    {
        foreach ([
            [['enabled' => true], true, ['mappings']],
            [['enabled' => true, 'doctrine' => ['entity_manager' => 'images']], true, ['entity_managers']],
            [['enabled' => true, 'pointer_store' => 'app.pointers'], true, null],
            [['enabled' => false], true, null],
            [['enabled' => true], false, null],
        ] as [$ownerImages, $doctrine, $expected]) {
            $container = new ContainerBuilder();
            if ($doctrine) {
                $container->registerExtension(new FakeDoctrineExtension());
            }
            $container->prependExtensionConfig('nowo_user_kit', ['owner_images' => $ownerImages]);
            OwnerImagesLoader::prepend($container);
            $orm = $doctrine ? ($container->getExtensionConfig('doctrine')[0]['orm'] ?? null) : null;
            self::assertSame($expected, $orm === null ? null : array_keys($orm));
        }
    }
}
