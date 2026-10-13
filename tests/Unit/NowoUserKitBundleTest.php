<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit;

use Nowo\UserKitBundle\DependencyInjection\Compiler\AccountStatusUserCheckerPass;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\NowoUserKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class NowoUserKitBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        $bundle    = new NowoUserKitBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertInstanceOf(ExtensionInterface::class, $extension);
        $this->assertInstanceOf(NowoUserKitExtension::class, $extension);
        $this->assertSame('nowo_user_kit', $extension->getAlias());
    }

    public function testBuildRegistersAccountStatusUserCheckerPass(): void
    {
        $container = new ContainerBuilder();
        (new NowoUserKitBundle())->build($container);

        $passes = array_filter(
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            static fn (object $pass): bool => $pass instanceof AccountStatusUserCheckerPass,
        );
        $this->assertCount(1, $passes);
    }
}
