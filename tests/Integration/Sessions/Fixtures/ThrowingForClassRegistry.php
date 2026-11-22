<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use RuntimeException;

/**
 * Doctrine registry that throws when asked for the manager of one class (audit YQ-L2: an actor whose reference cannot
 * be read), and delegates everything else. With `$database`, the failure is a real DBAL error of the default connection
 * (audit YR-U-L1: a database failure is never swallowed).
 */
final readonly class ThrowingForClassRegistry implements ManagerRegistry
{
    /**
     * @param class-string $failing
     */
    public function __construct(private ManagerRegistry $inner, private string $failing, private bool $database = false)
    {
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        if ($class === $this->failing) {
            $connection = $this->inner->getConnection();
            if ($this->database && $connection instanceof Connection) {
                $connection->executeQuery('SELECT yr_missing FROM yr_missing_table');
            }

            throw new RuntimeException('No manager for the actor of alice@example.com');
        }

        return $this->inner->getManagerForClass($class);
    }

    public function getDefaultConnectionName(): string
    {
        return $this->inner->getDefaultConnectionName();
    }

    public function getConnection(?string $name = null): object
    {
        return $this->inner->getConnection($name);
    }

    public function getConnections(): array
    {
        return $this->inner->getConnections();
    }

    public function getConnectionNames(): array
    {
        return $this->inner->getConnectionNames();
    }

    public function getDefaultManagerName(): string
    {
        return $this->inner->getDefaultManagerName();
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->inner->getManager($name);
    }

    public function getManagers(): array
    {
        return $this->inner->getManagers();
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->inner->resetManager($name);
    }

    public function getManagerNames(): array
    {
        return $this->inner->getManagerNames();
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->inner->getRepository($persistentObject, $persistentManagerName);
    }
}
