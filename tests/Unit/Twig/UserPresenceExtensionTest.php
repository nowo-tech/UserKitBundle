<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Twig;

use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Nowo\UserKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\Twig\UserPresenceExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class UserPresenceExtensionTest extends TestCase
{
    public function testUserIsOnlineFunction(): void
    {
        $resolver = new UserPresenceResolver(
            ProfileRegistryFactory::single(stdClass::class),
            PropertyAccess::createPropertyAccessor(),
        );
        $extension = new UserPresenceExtension($resolver);

        $this->assertFalse($extension->isOnline(new stdClass()));
        $this->assertSame('user_is_online', $extension->getFunctions()[0]->getName());
    }
}
