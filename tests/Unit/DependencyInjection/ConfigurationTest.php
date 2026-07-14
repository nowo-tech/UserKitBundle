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

        $this->assertNull($config['user_class']);
        $this->assertTrue($config['account_status']['enabled']);
        $this->assertSame('enabled', $config['account_status']['field']);
        $this->assertFalse($config['last_activity']['enabled']);
        $this->assertSame(300, $config['last_activity']['online_threshold']);
    }
}
