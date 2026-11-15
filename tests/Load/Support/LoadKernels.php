<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\SessionsTestKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function getenv;
use function is_string;

/**
 * Kernels of the real-database load tests (`USER_KIT_LOAD_DATABASE_URL`), shared by the tests and their worker
 * processes: the session registry test kernel on that server, with the slow device id provider and a host eraser.
 */
final class LoadKernels
{
    /** The server database of the load tests; null = the tests are skipped. */
    public static function url(): ?string
    {
        $url = getenv('USER_KIT_LOAD_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param string $id cache directory (one per process: two processes never warm one cache together)
     * @param array<string, mixed> $userKit `nowo_user_kit` configuration
     */
    public static function kernel(string $id, array $userKit): SessionsTestKernel
    {
        return new SessionsTestKernel('load-' . $id, $userKit, null, [], false, static function (ContainerConfigurator $c) use ($userKit): void {
            $c->services()->set(SlowDeviceId::class)->public();
            $c->services()->set(AnonymisingEraser::class)->tag('nowo_user_kit.personal_data.eraser', ['priority' => 10]);
            if (isset($userKit['personal_data'])) {
                $c->services()->alias('load.eraser', PersonalDataEraser::class)->public();
            }
        }, ['database_url' => self::url()]);
    }
}
