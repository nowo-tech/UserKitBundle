<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\EventSubscriber;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class LastActivitySubscriberExtendedTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $this->assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 0]],
            LastActivitySubscriber::getSubscribedEvents(),
        );
    }

    public function testIgnoresWrongUserClass(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new WrongClassUser(), 'main', ['ROLE_USER']));

        $subscriber = new LastActivitySubscriber(
            ActivityUser::class,
            'lastActivityAt',
            0,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testIgnoresEmptyUserIdentifier(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new EmptyIdUser(), 'main', ['ROLE_USER']));

        $subscriber = new LastActivitySubscriber(
            EmptyIdUser::class,
            'lastActivityAt',
            0,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testUpdatesViaPropertyAccessor(): void
    {
        $user         = new PropertyActivityUser();
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $subscriber = new LastActivitySubscriber(
            PropertyActivityUser::class,
            'lastActivityAt',
            0,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));

        $this->assertNotNull($user->lastActivityAt);
    }

    public function testSkipsWhenFieldIsNotWritable(): void
    {
        $user         = new ReadOnlyActivityUser();
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $subscriber = new LastActivitySubscriber(
            ReadOnlyActivityUser::class,
            'lastActivityAt',
            0,
            $em,
            $tokenStorage,
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
    }
}

class WrongClassUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'wrong';
    }
}

class EmptyIdUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    /** @phpstan-return string */
    public function getUserIdentifier(): string
    {
        return '';
    }
}

class PropertyActivityUser implements UserInterface
{
    public ?DateTimeInterface $lastActivityAt = null;

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'property-user';
    }
}

class ReadOnlyActivityUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'readonly-user';
    }
}
