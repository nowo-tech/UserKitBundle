<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\SpySessionInvalidator;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;

/**
 * Proves the Doctrine entity listener is attached to the configured user class so the
 * session invalidator runs when an account is disabled.
 */
final class AccountDisabledListenerKernelTest extends TestCase
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

    public function testDisablingUserCallsSessionInvalidator(): void
    {
        [$entityManager, $spy] = $this->boot(true);

        $user = new User('carol', 'secret');
        $entityManager->persist($user);
        $entityManager->flush();
        $this->assertSame([], $spy->invalidated);

        $user->setEnabled(false);
        $entityManager->flush();

        $this->assertSame([$user], $spy->invalidated);
    }

    public function testInvalidatorIsNotCalledWhenFeatureIsOff(): void
    {
        [$entityManager, $spy] = $this->boot(false);

        $user = new User('dave', 'secret');
        $entityManager->persist($user);
        $entityManager->flush();

        $user->setEnabled(false);
        $entityManager->flush();

        $this->assertSame([], $spy->invalidated);
    }

    /**
     * @return array{EntityManagerInterface, SpySessionInvalidator}
     */
    private function boot(bool $invalidate): array
    {
        $this->kernel = new TestKernel(['account_status' => ['invalidate_sessions_on_disable' => $invalidate]]);
        $this->kernel->boot();

        $container     = $this->kernel->getContainer();
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $spy           = $container->get(SpySessionInvalidator::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->assertInstanceOf(SpySessionInvalidator::class, $spy);

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(User::class)]);

        return [$entityManager, $spy];
    }
}
