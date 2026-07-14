<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\EventSubscriber;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class LastActivitySubscriberTest extends TestCase
{
    public function testUpdatesLastActivityOnMainRequest(): void
    {
        $user         = new ActivityUser();
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $subscriber = new LastActivitySubscriber(
            ActivityUser::class,
            'lastActivityAt',
            0,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event  = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        $this->assertNotNull($user->getLastActivityAt());
    }

    public function testThrottlesUpdates(): void
    {
        $user         = new ActivityUser();
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $subscriber = new LastActivitySubscriber(
            ActivityUser::class,
            'lastActivityAt',
            60,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $event  = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);
        $subscriber->onKernelRequest($event);
    }
}

class ActivityUser implements UserInterface, LastActivityInterface
{
    private ?DateTimeInterface $lastActivityAt = null;

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'activity';
    }

    public function getLastActivityAt(): ?DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(DateTimeInterface $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }
}
