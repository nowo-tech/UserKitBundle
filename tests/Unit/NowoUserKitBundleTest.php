<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit;

use Nowo\UserKitBundle\NowoUserKitBundle;
use PHPUnit\Framework\TestCase;

final class NowoUserKitBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        $bundle = new NowoUserKitBundle();
        $this->assertSame('nowo_user_kit', $bundle->getContainerExtension()->getAlias());
    }
}
