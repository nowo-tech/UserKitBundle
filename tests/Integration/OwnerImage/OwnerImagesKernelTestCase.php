<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\MemoryBuckets;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function crc32;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;
use function ob_get_clean;
use function ob_start;
use function pack;
use function str_repeat;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;

/**
 * Boots {@see OwnerImagesTestKernel}, creates every table and the users `alice` and `bob` (password `pw`).
 */
abstract class OwnerImagesKernelTestCase extends TestCase
{
    protected ?OwnerImagesTestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-user-kit-owner-images');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $userKit
     * @param Closure(ContainerConfigurator): void|null $extra
     * @param array<string, mixed>|null $auditKit 1.20: `nowo_audit_kit` configuration (null: no AuditKitBundle)
     */
    protected function boot(array $userKit = [], ?Closure $extra = null, string $variant = '', ?array $auditKit = null): OwnerImagesTestKernel
    {
        $this->kernel = new OwnerImagesTestKernel($userKit, $extra, $variant . static::class . $this->name(), $auditKit);
        $this->kernel->boot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $all  = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($all);
        $tool->createSchema($all);
        foreach (['alice', 'bob'] as $name) {
            $em->persist(new User($name, 'pw'));
        }
        $em->flush();
        $em->clear();

        return $this->kernel;
    }

    protected function container(): ContainerInterface
    {
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->container()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function user(string $name): User
    {
        return $this->em()->find(User::class, $name) ?? self::fail('no user ' . $name);
    }

    protected function buckets(): MemoryBuckets
    {
        return $this->service(MemoryBuckets::class);
    }

    protected function browser(): KernelBrowser
    {
        $browser = $this->container()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $browser->disableReboot(); // the in-memory database lives in this kernel

        return $browser;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    protected static function png(int $width = 300, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 20, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** `image/webp` for a RIFF WebP, else null. */
    public static function sniff(string $bytes): ?string
    {
        return str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' ? 'image/webp' : null;
    }

    /** A PNG signature and IHDR claiming the size (decompression bomb header). */
    protected static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . str_repeat("\0", 64);
    }
}
