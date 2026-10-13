<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\PersonalData\ErasureGuardInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\ErasureVeto;
use Nowo\UserKitBundle\PersonalData\ErasureVetoedException;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PersonalDataEraserTest extends TestCase
{
    public function testUnmanagedUserClassIsRejected(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        (new PersonalDataEraser([], [], $doctrine))->erase(new stdClass());
    }

    public function testUnsupportedErasersAreSkipped(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($entityManager));
        $entityManager->expects($this->once())->method('flush');
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($entityManager);

        $eraser = static fn (string $key, bool $supports): PersonalDataEraserInterface => new class($key, $supports) implements PersonalDataEraserInterface {
            public function __construct(private readonly string $key, private readonly bool $supports)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function supports(object $user): bool
            {
                return $this->supports;
            }

            public function erase(ErasureRequest $request): void
            {
            }
        };

        $this->assertSame(['a', 'c'], (new PersonalDataEraser([$eraser('a', true), $eraser('b', false), $eraser('c', true)], [], $doctrine))->erase(new stdClass()));
    }

    public function testAllVetoesAreCollected(): void
    {
        $guard = static fn (?ErasureVeto $veto): ErasureGuardInterface => new class($veto) implements ErasureGuardInterface {
            public function __construct(private readonly ?ErasureVeto $veto)
            {
            }

            public function check(ErasureRequest $request): ?ErasureVeto
            {
                return $this->veto;
            }
        };

        $eraser = new PersonalDataEraser([], [
            $guard(new ErasureVeto('sole_owner', '', ['projects' => ['p1']])),
            $guard(null),
            $guard(new ErasureVeto('last_admin')),
        ], $this->createMock(ManagerRegistry::class));

        $this->assertCount(2, $eraser->check(new stdClass()));

        try {
            $eraser->erase(new stdClass());
            $this->fail('Expected a veto.');
        } catch (ErasureVetoedException $exception) {
            $this->assertSame(['sole_owner', 'last_admin'], $exception->getReasons());
            $this->assertFalse($exception->hasReason('other'));
            $this->assertSame('Personal data erasure vetoed: sole_owner, last_admin.', $exception->getMessage());
            $this->assertSame(['projects' => ['p1']], $exception->vetoes[0]->details);
        }
    }

    public function testRequestSelfService(): void
    {
        $user  = new stdClass();
        $admin = new stdClass();

        $this->assertTrue((new ErasureRequest($user))->isSelfService());
        $this->assertTrue((new ErasureRequest($user, $user))->isSelfService());
        $this->assertFalse((new ErasureRequest($user, $admin, ['scope' => 'admin']))->isSelfService());
    }
}
