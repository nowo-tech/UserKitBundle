<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Doctrine\DBAL\Connection;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\Owner\SessionOwnerResolverInterface;
use RuntimeException;

/**
 * Host owner resolver that maps members like the default resolver and queries the database for any other object, a
 * query that fails (audit YR-U-L1: a DB-querying resolver failing inside the host's open transaction).
 */
final readonly class DatabaseFailingActorOwnerResolver implements SessionOwnerResolverInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(object $user): SessionUser
    {
        if ($user instanceof Member) {
            return new SessionUser(Member::class, (string) $user->getId());
        }
        $this->connection->executeQuery('SELECT yr_missing FROM yr_missing_table');

        throw new RuntimeException('Unreachable: the query fails.');
    }
}
