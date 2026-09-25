<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\NowoUserKitBundle;
use PHPUnit\Framework\TestCase;
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
}
