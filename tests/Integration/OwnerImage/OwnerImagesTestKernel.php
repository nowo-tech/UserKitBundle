<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\HostBuckets;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\MemoryBuckets;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function array_replace_recursive;
use function dirname;
use function getenv;
use function is_string;
use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Kernel of the owner image tests (1.18): Framework, Security (http_basic over the Doctrine users), Twig, Doctrine
 * (SQLite in memory, or `USER_KIT_TEST_DB_URL`), DoctrineEncrypt (a generated test key), FormKit, UiKit, StorageKit 1.6 on
 * in-memory buckets (no Instance Settings: the application bucket comes from {@see HostBuckets}) and UserKit with
 * `owner_images`.
 */
final class OwnerImagesTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $userKit `nowo_user_kit` configuration (merged over the defaults)
     * @param Closure(ContainerConfigurator): void|null $extra host services
     * @param array<string, mixed>|null $auditKit 1.20: `nowo_audit_kit` configuration (null: no AuditKitBundle)
     */
    public function __construct(
        private readonly array $userKit = [],
        private readonly ?Closure $extra = null,
        private readonly string $variant = '',
        private readonly ?array $auditKit = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoDoctrineEncryptBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoStorageKitBundle();
        yield new NowoUserKitBundle();
        if ($this->auditKit !== null) {
            yield new NowoAuditKitBundle();
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-user-kit-owner-images/' . $this->id() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-user-kit-owner-images/' . $this->id() . '/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . $this->id();
    }

    public static function databaseUrl(): string
    {
        $url = getenv('USER_KIT_TEST_DB_URL');

        return is_string($url) && $url !== '' ? $url : 'sqlite:///:memory:';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
            'php_errors'           => ['log' => true],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => false],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_client'          => true,
            'lock'                 => 'in-memory',
            'validation'           => true,
            'mailer'               => ['enabled' => false],
            'messenger'            => ['enabled' => false],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('security', [
            'password_hashers' => [User::class => 'plaintext'],
            'providers'        => ['app' => ['entity' => ['class' => User::class, 'property' => 'username']]],
            'firewalls'        => ['main' => ['pattern' => '^/', 'http_basic' => null, 'provider' => 'app', 'stateless' => true, 'lazy' => true]],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm'  => ['mappings' => ['UserKitTest' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => __DIR__ . '/../Fixtures/Entity',
                'prefix'    => 'Nowo\\UserKitBundle\\Tests\\Integration\\Fixtures\\Entity',
            ], 'UserKitOwnerImageTest' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => __DIR__ . '/Fixtures/Entity',
                'prefix'    => 'Nowo\\UserKitBundle\\Tests\\Integration\\OwnerImage\\Fixtures\\Entity',
            ], 'NowoUserKitModel' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => dirname(__DIR__, 3) . '/src/Model',
                'prefix'    => 'Nowo\\UserKitBundle\\Model',
            ]]],
        ]);
        $container->extension('nowo_doctrine_encrypt', [
            'default_profile'      => 'default',
            'secret_permissions'   => ['enabled' => false],
            'generate_missing_key' => true,
            'on_decrypt_failure'   => 'null',
            'profiles'             => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => sys_get_temp_dir() . '/nowo-user-kit-owner-images/' . $this->id() . '/keys']],
        ]);
        $container->extension('nowo_storage_kit', [
            'config_profile_provider' => HostBuckets::class,
            'instance_profile'        => 'primary',
            'endpoints'               => ['allowed_hosts' => ['minio'], 'resolve_dns' => false],
            'web_ui'                  => ['enabled' => false],
            'security'                => ['allow_unauthenticated' => false],
        ]);
        if ($this->auditKit !== null) {
            $container->extension('doctrine', ['orm' => ['mappings' => ['UserKitOwnerImageAuditLog' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => __DIR__ . '/Fixtures/AuditLog',
                'prefix'    => 'Nowo\\UserKitBundle\\Tests\\Integration\\OwnerImage\\Fixtures\\AuditLog',
            ]]]]);
            $container->extension('nowo_audit_kit', $this->auditKit);
        }
        $container->extension('nowo_user_kit', array_replace_recursive(isset($this->userKit['profiles']) ? [] : ['user_class' => User::class], [
            'owner_images'  => ['enabled' => true],
            'personal_data' => ['enabled' => true],
        ], $this->userKit));
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(HostBuckets::class)->public();
        $services->set(MemoryBuckets::class)->public();
        $services->alias('nowo_storage_kit.connection_factory', MemoryBuckets::class)->public();
        $services->alias('test.personal_data.exporter', PersonalDataExporter::class)->public();
        $services->alias('test.personal_data.eraser', PersonalDataEraser::class)->public();
        if ($this->extra instanceof Closure) {
            ($this->extra)($container);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_storage_kit');
    }

    private function id(): string
    {
        return md5(serialize([$this->userKit, $this->variant, $this->auditKit]));
    }
}
