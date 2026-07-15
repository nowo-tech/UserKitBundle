<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\Presence\UserPresenceResolver;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserPresenceResolver $presenceResolver,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        $this->ensureDemoUserExists();

        $user = $this->getUser();
        $online = $user instanceof User ? $this->presenceResolver->isOnline($user) : false;
        $profile = $user instanceof User ? $this->profileRegistry->resolveForObject($user) : null;

        return $this->render('demo/index.html.twig', [
            'user' => $user,
            'online' => $online,
            'profile' => $profile,
        ]);
    }

    #[Route('/login', name: 'demo_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $this->ensureDemoUserExists();

        return $this->render('demo/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'demo_logout')]
    public function logout(): void
    {
        throw new \LogicException('Handled by Symfony security.');
    }

    private function ensureDemoUserExists(): void
    {
        $repository = $this->entityManager->getRepository(User::class);
        $user = $repository->findOneBy(['email' => 'demo@user-kit.test']);

        if ($user instanceof User) {
            return;
        }

        $user = (new User())
            ->setEmail('demo@user-kit.test')
            ->setPassword($this->passwordHasher->hashPassword(new User(), 'demo'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
