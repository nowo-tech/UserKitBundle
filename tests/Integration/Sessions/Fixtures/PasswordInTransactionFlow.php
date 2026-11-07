<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Test controller: the signed-in member's password changes inside a transaction; `end=commit|rollback` ends it.
 */
final readonly class PasswordInTransactionFlow
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $member = $this->tokens->getToken()?->getUser();
        if (!$member instanceof Member) {
            throw new LogicException('Sign in first.');
        }
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $member->setPassword('changed');
        $this->em->flush();
        $request->query->getString('end') === 'commit' ? $connection->commit() : $connection->rollBack();

        return new Response('done');
    }
}
