<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Bare extension so prependExtensionConfig() / getExtensionConfig() accept an alias in container unit tests.
 */
final class FakeExtension extends Extension
{
    public function __construct(private readonly string $alias)
    {
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return $this->alias;
    }
}
