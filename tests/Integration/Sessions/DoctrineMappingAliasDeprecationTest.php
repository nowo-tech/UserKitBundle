<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Sessions;

use Nowo\UserKitBundle\Sessions\Entity\UserSession;

use const E_USER_DEPRECATED;

/**
 * DoctrineBundle 3.4 deprecates the `alias` option of ORM mappings: the mappings UserKit prepends must not use it, and
 * booting a kernel (which makes DoctrineBundle process them) must not raise that deprecation.
 */
final class DoctrineMappingAliasDeprecationTest extends SessionsKernelTestCase
{
    public function testBootTriggersNoMappingAliasDeprecation(): void
    {
        $deprecations = [];
        // Test-only, restored in finally: collects the deprecation notices raised while the container compiles.
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool { // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
            if (preg_match('/\balias\b/i', $message) === 1) {
                $deprecations[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->boot(['remember_me_epoch' => ['enabled' => true]]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertSame(UserSession::class, $this->em()->getClassMetadata(UserSession::class)->getName());
    }
}
