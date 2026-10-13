<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\AppUserChecker;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function dirname;

/**
 * Boots a real kernel (SecurityBundle + http_basic + Doctrine entity provider) and proves that
 * a disabled account is rejected without any manual firewall `user_checker` configuration.
 */
final class AccountStatusUserCheckerKernelTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        if ($this->kernel instanceof TestKernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove(dirname($cacheDir));
            $this->kernel = null;
        }
    }

    public function testDisabledUserIsRejectedOnDefaultFirewall(): void
    {
        $this->bootWithUsers(new TestKernel());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->requestAs('bob', 'secret')->getStatusCode());
    }

    public function testEnabledUserIsAuthenticatedOnDefaultFirewall(): void
    {
        $this->bootWithUsers(new TestKernel());

        $response = $this->requestAs('alice', 'secret');
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function testDisabledUserIsRejectedWhenFirewallUsesAnAppUserChecker(): void
    {
        $kernel = new TestKernel(appUserChecker: true);
        $this->bootWithUsers($kernel);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->requestAs('bob', 'secret')->getStatusCode());

        $appChecker = $kernel->getContainer()->get(AppUserChecker::class);
        $this->assertInstanceOf(AppUserChecker::class, $appChecker);
        $this->assertGreaterThan(0, $appChecker->calls, 'The application user_checker must still run.');
    }

    public function testDisabledUserCanLogInWhenAccountStatusIsDisabled(): void
    {
        $this->bootWithUsers(new TestKernel(['account_status' => ['enabled' => false]]));

        $this->assertSame(Response::HTTP_OK, $this->requestAs('bob', 'secret')->getStatusCode());
    }

    public function testFirewallWithoutSecurityIsLeftUntouched(): void
    {
        $kernel = $this->bootWithUsers(new TestKernel());

        $response = $kernel->handle(Request::create('/public'));
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function bootWithUsers(TestKernel $kernel): TestKernel
    {
        $this->kernel = $kernel;
        $kernel->boot();

        $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(User::class)]);

        $entityManager->persist(new User('alice', 'secret'));
        $entityManager->persist((new User('bob', 'secret'))->setEnabled(false));
        $entityManager->flush();
        $entityManager->clear();

        return $kernel;
    }

    private function requestAs(string $username, string $password): Response
    {
        $this->assertInstanceOf(TestKernel::class, $this->kernel);

        return $this->kernel->handle(Request::create('/', 'GET', server: [
            'PHP_AUTH_USER' => $username,
            'PHP_AUTH_PW'   => $password,
        ]));
    }
}
