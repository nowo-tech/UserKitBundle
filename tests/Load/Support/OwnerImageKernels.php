<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\OwnerImagesTestKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;
use function ob_get_clean;
use function ob_start;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The owner images kernel of the load tests (and their worker processes), on `USER_KIT_TEST_DB_URL` (the test sets it
 * to `USER_KIT_LOAD_DATABASE_URL`), with the slow pointer store decorator.
 */
final class OwnerImageKernels
{
    public static function kernel(string $variant): OwnerImagesTestKernel
    {
        return new OwnerImagesTestKernel([], static function (ContainerConfigurator $c): void {
            $c->services()->set(SlowPointers::class)->decorate(DbalOwnerImagePointerStore::class)->args([service('.inner')]);
        }, 'load-' . $variant);
    }

    /**
     * A PNG of the given size.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $width = 300, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 20, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
