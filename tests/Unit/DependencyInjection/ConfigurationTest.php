<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection;

use Nowo\UserKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        $this->assertSame('default', $config['default_profile']);
        $this->assertNull($config['profiles']['default']['user_class']);
        $this->assertTrue($config['profiles']['default']['account_status']['enabled']);
        $this->assertSame('enabled', $config['profiles']['default']['account_status']['field']);
        $this->assertFalse($config['profiles']['default']['last_activity']['enabled']);
        $this->assertSame(300, $config['profiles']['default']['last_activity']['online_threshold']);
    }

    public function testLegacyFlatConfigurationIsNormalizedToProfiles(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'    => 'App\\Entity\\User',
            'last_activity' => ['enabled' => true, 'online_threshold' => 120],
        ]]);

        $this->assertSame('App\\Entity\\User', $config['profiles']['default']['user_class']);
        $this->assertTrue($config['profiles']['default']['last_activity']['enabled']);
        $this->assertSame(120, $config['profiles']['default']['last_activity']['online_threshold']);
    }
}
