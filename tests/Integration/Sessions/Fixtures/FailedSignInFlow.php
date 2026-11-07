<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Test controller: a sign-in over an existing session that fails, undone the way AuthKit's
 * `SocialHandlerSignInRollback::restore()` does it (1.38.0), with the `adopt()` call that method needs.
 *
 * Query: `target` the account the failed sign-in is for; `tx=1` the sign-in runs in a transaction that rolls back, as
 * `SocialAccountLinker::linkAndSignIn()` does; `revoke=before` the earlier session is revoked once the handler is
 * running and before the sign-in deletes its row, `revoke=after` once that row is gone, `revoke=rolled-back` on the row a rollback brought back; `adopt=0` leaves the call out.
 */
final readonly class FailedSignInFlow
{
    public function __construct(
        private Security $security,
        private TokenStorageInterface $tokens,
        private UserSessionManager $sessions,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $before  = $this->tokens->getToken() ?? throw new LogicException('Sign in first.');
        $earlier = $before->getUser() ?? throw new LogicException('No user.');
        $target  = $this->em->getRepository(Member::class)->findOneBy(['email' => $request->query->getString('target')]) ?? throw new LogicException('Unknown target.');
        $tx      = $request->query->getBoolean('tx');
        if ($request->query->getString('revoke') === 'before') {
            $this->sessions->revokeAll($earlier, null, RevocationReason::AccountChanged);
        }
        $connection = $this->em->getConnection();
        if ($tx) {
            $connection->beginTransaction();
        }

        try {
            $this->security->login($target, 'form_login', 'main');
            if ($request->query->getString('revoke') === 'after') {
                $this->sessions->revokeAll($earlier, null, RevocationReason::AccountChanged);
            }

            throw new RuntimeException('The handler failed.');
        } catch (RuntimeException) {
            if ($tx) {
                $connection->rollBack();
            }
            if ($request->query->getString('revoke') === 'rolled-back') {
                $this->sessions->revokeAll($earlier, null, RevocationReason::AccountChanged);
            }
            $this->tokens->setToken($before);
            $session  = $request->getSession();
            $previous = $session->getId();
            $owner    = $this->sessions->owner($target)?->key();
            $markers  = $this->sessions->markers($session);
            foreach ($markers as $context => $marker) {
                if ($marker['u'] === $owner) {
                    unset($markers[$context]);
                }
            }
            $session->set(UserSessionManager::ATTRIBUTE, $markers);
            $session->migrate(true);
            $this->sessions->rekey($session, $previous);
            $this->sessions->endSession($previous, $target);
            if ($request->query->getBoolean('adopt', true)) {
                $this->sessions->adopt($request, $earlier);
            }
        }

        return new Response('restored');
    }
}
