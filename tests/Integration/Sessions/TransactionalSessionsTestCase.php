<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionStartedEvent;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\FailedSignInFlow;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\PasswordInTransactionFlow;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionEventLog;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionsTestKernel;
use Nowo\UserKitBundle\Tests\Support\SharedMemoryPool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function getenv;
use function is_string;
use function md5;

/**
 * Runs on SQLite, or on the database of `USER_KIT_TEST_DB_URL` (the `real-databases` CI job: MySQL and PostgreSQL, where
 * a transaction is a transaction). `USER_KIT_TEST_DB_PLATFORM` makes the job fail if it silently ran on something else.
 */
abstract class TransactionalSessionsTestCase extends SessionsKernelTestCase
{
    /**
     * @param array<string, mixed> $sessions `nowo_user_kit.sessions`
     * @param array{back_firewall?: bool} $options test kernel options
     * @param array<string, mixed> $userKit other `nowo_user_kit` keys
     */
    protected function setUpDatabase(string $store = 'database', array $sessions = [], array $options = [], ?Closure $routes = null, array $userKit = []): void
    {
        $url    = getenv('USER_KIT_TEST_DB_URL');
        $config = $userKit + ['sessions' => array_replace_recursive(
            ['enabled' => true, 'web_ui' => ['enabled' => true], 'revocation' => ['store' => $store] + ($store === 'cache' ? ['cache_pool' => 'test.revocations'] : [])],
            $sessions,
        )];
        $this->kernel = new SessionsTestKernel(
            md5(serialize([$config, $options, static::class, $this->name()])),
            $config,
            $routes ?? static function (RoutingConfigurator $r): void {
                $r->import('.', 'nowo_user_kit_sessions');
                $r->add('page', '/page')->controller('kernel::page');
                $r->add('login', '/login')->controller('kernel::login');
                $r->add('logout', '/logout');
                $r->add('page_stateless', '/page/stateless')->controller('kernel::statelessPage')->defaults(['_stateless' => true]);
                $r->add('flow_failed', '/page/failed')->controller(FailedSignInFlow::class);
                $r->add('flow_password', '/page/tx-password')->controller(PasswordInTransactionFlow::class);
            },
            [],
            false,
            static function (ContainerConfigurator $c) use ($userKit): void {
                $c->services()->set('test.revocations', SharedMemoryPool::class)->public();
                if (isset($userKit['personal_data'])) {
                    $c->services()->alias('test.eraser', PersonalDataEraser::class)->public();
                }
                $c->services()->set(SessionEventLog::class)->public()
                    ->tag('kernel.event_listener', ['event' => UserSessionsRevokedEvent::class, 'method' => 'onRevoked'])
                    ->tag('kernel.event_listener', ['event' => UserSessionStartedEvent::class, 'method' => 'onStarted']);
                foreach ([FailedSignInFlow::class, PasswordInTransactionFlow::class] as $flow) {
                    $c->services()->set($flow)->public()->autowire()->tag('controller.service_arguments');
                }
            },
            ['database_url' => is_string($url) && $url !== '' ? $url : null] + $options,
        );
        $this->kernel->boot();
        $em      = $this->em();
        $classes = [$em->getClassMetadata(Member::class), $em->getClassMetadata(UserSession::class)];
        $tool    = new SchemaTool($em);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
        $this->assertExpectedPlatform();
    }

    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function log(): SessionEventLog
    {
        $log = $this->container()->get(SessionEventLog::class);
        self::assertInstanceOf(SessionEventLog::class, $log);

        return $log;
    }

    /**
     * @return list<string>
     */
    protected function revokedReasons(): array
    {
        return array_map(static fn (UserSessionsRevokedEvent $event): string => $event->reasonCode(), $this->log()->revoked);
    }

    protected function fresh(Member $member): Member
    {
        $this->em()->clear();

        return $this->em()->find(Member::class, $member->getId()) ?? self::fail('The member is gone.');
    }

    /**
     * @return int every row of the table, revoked ones included
     */
    protected function allRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->store()->table());
    }

    private function assertExpectedPlatform(): void
    {
        $platform = $this->connection()->getDatabasePlatform();
        $expected = getenv('USER_KIT_TEST_DB_PLATFORM');
        match ($expected) {
            'mysql'      => self::assertInstanceOf(AbstractMySQLPlatform::class, $platform),
            'postgresql' => self::assertInstanceOf(PostgreSQLPlatform::class, $platform),
            default      => self::assertInstanceOf(SQLitePlatform::class, $platform),
        };
    }
}
