<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Stands for DoctrineBundle's extension (prepend checks `hasExtension('doctrine')`).
 */
final class FakeDoctrineExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'doctrine';
    }
}
