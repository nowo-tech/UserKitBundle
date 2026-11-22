<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Collation;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;
use Nowo\UserKitBundle\Doctrine\UserKitExactKeyColumns;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\OwnerCollationListener;
use Nowo\UserKitBundle\Sessions\Doctrine\TablePrefixListener;

use function dirname;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 on a real server (USER_KIT_LOAD_DATABASE_URL: MySQL 8.4 and MariaDB 11.4 in CI): the bundle's own gate
 * over its own declaration.
 *
 * The three tables are created by the schema tool with the listeners the bundle registers, under a non-empty table
 * prefix, and every declared column is then read back from `information_schema`. An owner column that lost its binary
 * NO PAD collation — or a new identity column nobody collated — fails *this bundle's* pipeline, long before a host
 * installs it; the prefix also proves the session tables' names are resolved from the configuration while the
 * owner-image pointer table, which no host can rename, keeps its own name.
 *
 * Skips cleanly on PostgreSQL and SQLite, which compare exactly by default, and without the variable.
 */
final class ExactKeyColumnsGateTest extends ExactKeyColumnsTestCase
{
    /** A non-empty prefix: the declaration must follow the configured table name. */
    private const PREFIX = 'exactkey_';

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'USER_KIT_LOAD_DATABASE_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new UserKitExactKeyColumns(self::PREFIX, true, true, true)];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $base   = dirname(__DIR__, 2) . '/src';
        $config = ORMSetup::createAttributeMetadataConfiguration([
            $base . '/Sessions/Entity',
            $base . '/Sessions/Epoch/Entity',
            $base . '/OwnerImage/Entity',
        ], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener(self::PREFIX));
        $events->addEventListener(ToolEvents::postGenerateSchema, new OwnerCollationListener());
        $events->addEventListener(ToolEvents::postGenerateSchema, new PointerCollationListener());

        $manager = new EntityManager($connection, $config, $events);
        /** @var list<ClassMetadata<object>> $classes */
        $classes = $manager->getMetadataFactory()->getAllMetadata();

        $tool = new SchemaTool($manager);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
