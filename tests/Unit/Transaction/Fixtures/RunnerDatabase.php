<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Transaction\Fixtures;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * An in-memory SQLite entity manager with the runner fixtures (Note, Tag).
 */
final class RunnerDatabase
{
    public static function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true, sys_get_temp_dir() . '/nowo-user-kit-runner-proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }
}
