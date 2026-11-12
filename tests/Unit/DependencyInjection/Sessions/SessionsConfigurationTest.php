<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\DependencyInjection\Sessions;

use Nowo\UserKitBundle\DependencyInjection\Configuration;
use Nowo\UserKitBundle\DependencyInjection\SessionsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class SessionsConfigurationTest extends TestCase
{
    public function testSafeDefaults(): void
    {
        $sessions = $this->process([])['sessions'];

        self::assertFalse($sessions['enabled']);
        self::assertSame('%kernel.secret%', $sessions['hash_secret']);
        self::assertSame(86400, $sessions['idle_lifetime']);
        self::assertSame(300, $sessions['touch_interval']);
        self::assertSame('database', $sessions['revocation']['store']);
        self::assertNull($sessions['revocation']['cache_pool']);
        self::assertSame([], $sessions['remember_me_cookies']);
        self::assertSame('truncate', $sessions['client']['ip']);
        self::assertSame('label', $sessions['client']['user_agent']);
        self::assertSame([], $sessions['client']['context_attributes'], 'Opt-in: no request attribute by default.');
        self::assertNull($sessions['limit']['max_sessions']);
        self::assertSame('evict_oldest', $sessions['limit']['policy']);
        self::assertTrue($sessions['password_change']['revoke_sessions']);
        self::assertSame('', $sessions['doctrine']['table_prefix']);
        self::assertFalse($sessions['web_ui']['enabled']);
        self::assertSame('/account/sessions', $sessions['web_ui']['path_prefix']);
        self::assertSame(['ROLE_USER'], $sessions['security']['access_roles']);
        self::assertSame(['ROLE_ADMIN'], $sessions['security']['manage_roles']);
        self::assertFalse($sessions['security']['allow_unauthenticated']);
    }

    public function testEmptyRolesAreAllowedWithACheckerOrInDemoMode(): void
    {
        self::assertSame([], $this->process(['security' => ['access_roles' => [], 'access_checker' => 'app.checker']])['sessions']['security']['access_roles']);
        self::assertSame([], $this->process(['security' => ['manage_roles' => [], 'allow_unauthenticated' => true]])['sessions']['security']['manage_roles']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty access roles (P1)' => [['security' => ['access_roles' => [' ']]], 'access_roles and manage_roles must each contain'];
        yield 'empty manage roles (P1)' => [['security' => ['manage_roles' => []]], 'access_roles and manage_roles must each contain'];
        yield 'touch >= idle' => [['idle_lifetime' => 600, 'touch_interval' => 600], 'touch_interval must be lower'];
        yield 'bad prefix' => [['doctrine' => ['table_prefix' => 'a-b']], 'table_prefix must match'];
        yield 'root path' => [['web_ui' => ['path_prefix' => '/']], 'path_prefix must start with'];
        yield 'relative admin path' => [['web_ui' => ['admin_path_prefix' => 'admin']], 'admin_path_prefix must start with'];
        yield 'same paths' => [['web_ui' => ['path_prefix' => '/s', 'admin_path_prefix' => '/s/']], 'must differ'];
        yield 'policy' => [['limit' => ['policy' => 'kick']], 'policy'];
        yield 'ip mode' => [['client' => ['ip' => 'full']], 'ip'];
        yield 'int context attribute (MU-U-I1)' => [['client' => ['context_attributes' => ['_device', 42]]], 'context_attributes entries must be attribute names'];
        yield 'bool context attribute (MU-U-I1)' => [['client' => ['context_attributes' => [true]]], 'context_attributes entries must be attribute names'];
        yield 'cache store without pool (L3)' => [['revocation' => ['store' => 'cache']], 'cache_pool is required'];
    }

    /**
     * @param array<string, mixed> $sessions
     */
    #[DataProvider('invalid')]
    public function testInvalidConfiguration(array $sessions, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        $this->process($sessions);
    }

    public function testRoles(): void
    {
        self::assertSame(['ROLE_A'], SessionsConfiguration::roles([' ROLE_A ', '', 3]));
        self::assertSame([], SessionsConfiguration::roles('ROLE_A'));
    }

    /**
     * @param array<string, mixed> $sessions
     *
     * @return array<string, mixed>
     */
    private function process(array $sessions): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => 'App\User', 'sessions' => $sessions]]);
    }
}
