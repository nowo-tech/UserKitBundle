<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\UserKitBundle\EventSubscriber\LastActivitySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class LastActivitySubscriberEdgeCasesTest extends TestCase
{
    public function testIgnoresSubRequestsAndAnonymousUsers(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $subscriber = new LastActivitySubscriber(
            ActivityUser::class,
            'lastActivityAt',
            0,
            $em,
            new TokenStorage(),
            PropertyAccess::createPropertyAccessor(),
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
    }
}
