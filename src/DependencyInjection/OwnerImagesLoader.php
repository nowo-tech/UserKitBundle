<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Upload\SquareImageProcessor;
use Nowo\StorageKitBundle\Upload\SquareImageUploaderInterface;
use Nowo\UserKitBundle\DependencyInjection\Compiler\OwnerImageAuditPass;
use Nowo\UserKitBundle\OwnerImage\Access\DefaultOwnerImageAccess;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessChecker;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessCheckerInterface;
use Nowo\UserKitBundle\OwnerImage\Access\OwnerImageAccessInterface;
use Nowo\UserKitBundle\OwnerImage\Audit\OwnerImageAuditListener;
use Nowo\UserKitBundle\OwnerImage\EventListener\OwnerImageMemoListener;
use Nowo\UserKitBundle\OwnerImage\Form\OwnerImageType;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImages;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\PersonalData\OwnerImageEraser;
use Nowo\UserKitBundle\OwnerImage\PersonalData\OwnerImageExportProvider;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\OwnerImage\Pointer\PointerCollationListener;
use Nowo\UserKitBundle\OwnerImage\Storage\OwnerImageStorageInterface;
use Nowo\UserKitBundle\OwnerImage\Storage\StorageKit\OwnerImageFileContext;
use Nowo\UserKitBundle\OwnerImage\Storage\StorageKit\StorageKitOwnerImageStorage;
use Nowo\UserKitBundle\OwnerImage\Twig\OwnerImageExtension;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Transaction\UserKitTransactionRunner;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Twig\Extension\AbstractExtension;

use function array_keys;
use function array_values;
use function class_exists;
use function in_array;
use function interface_exists;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * Registers `nowo_user_kit.owner_images` (1.18, only when `owner_images.enabled` is true) and prepends the Doctrine
 * mapping of the default pointer table.
 */
final class OwnerImagesLoader
{
    /** Service id prefix of the StorageKit file context of each kind. */
    public const CONTEXT_PREFIX = 'nowo_user_kit.owner_images.file_context.';

    /**
     * Prepends the mapping of `user_kit_owner_image` (feature on, no own pointer store) from the raw configuration.
     */
    public static function prepend(ContainerBuilder $container): void
    {
        $raw = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['owner_images'] ?? null)) {
                $raw = $config['owner_images'] + $raw;
            }
        }
        $store = $raw['pointer_store'] ?? null;
        if (($raw['enabled'] ?? false) !== true || (is_string($store) && $store !== '') || !$container->hasExtension('doctrine')) {
            return;
        }
        $mapping = ['mappings' => ['NowoUserKitOwnerImage' => [
            'type'      => 'attribute',
            'dir'       => __DIR__ . '/../OwnerImage/Entity',
            'prefix'    => 'Nowo\UserKitBundle\OwnerImage\Entity',
            'is_bundle' => false,
        ]]];
        $manager = $raw['doctrine']['entity_manager'] ?? null;
        $container->prependExtensionConfig('doctrine', ['orm' => is_string($manager) && $manager !== '' ? ['entity_managers' => [$manager => $mapping]] : $mapping]);
    }

    /**
     * Registers the services of the feature.
     *
     * @param array{enabled: bool, kinds: array<string, array{subject: string, read: string, shape: string, pointer?: array{entity: string, field: string, subject_field: string|null}}>, user_class: string|null, avatar_kind: string|null, access: string|null, pointer_store: string|null, audit?: array{enabled: bool, user_id_field: string|null, actions: array{uploaded: string, replaced: string, removed: string}, kinds: array<string, array{uploaded: string|null, replaced: string|null, removed: string|null}>}, storage: string|null, doctrine: array{entity_manager: string|null}, twig: array{sizes: array<string, int>}} $config
     * @param string|null $profileUserClass the user class of the only UserKit profile (null with several profiles):
     *                                      the default of `user_class`
     */
    public static function load(ContainerBuilder $container, array $config, bool $twig, bool $personalData, ?string $profileUserClass = null): void
    {
        $container->setParameter('nowo_user_kit.owner_images.enabled', $config['enabled']);
        if (!$config['enabled']) {
            return;
        }

        $kinds = [];
        foreach ($config['kinds'] as $name => $kind) {
            $kinds[$name] = new Definition(OwnerImageKind::class, [(string) $name, $kind['subject'], $kind['read'], $kind['shape']]);
        }
        $container->setDefinition(OwnerImageKinds::class, new Definition(OwnerImageKinds::class, [array_values($kinds)]));
        $userClass = is_string($config['user_class']) ? ltrim($config['user_class'], '\\') : $profileUserClass;
        if ($userClass !== null && !class_exists($userClass) && !interface_exists($userClass)) {
            throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.user_class: class or interface "%s" does not exist.', $userClass));
        }
        $container->setDefinition(OwnerImageSubjects::class, new Definition(OwnerImageSubjects::class, [new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE), $userClass]));

        $container->setDefinition(DefaultOwnerImageAccess::class, new Definition(DefaultOwnerImageAccess::class, [new Reference(OwnerImageSubjects::class)]));
        $container->setAlias(OwnerImageAccessInterface::class, self::serviceId($config['access']) ?? DefaultOwnerImageAccess::class)->setPublic(true);

        $store   = self::serviceId($config['pointer_store']);
        $columns = ColumnPointers::fromConfig($config['kinds'], $store === OwnerImagesConfiguration::POINTER_STORE_COLUMN);
        if ($store === OwnerImagesConfiguration::POINTER_STORE_COLUMN) {
            // 1.20: the pointer in a column of the owner's row (validated above: entity, field, one kind per column).
            $container->setDefinition(ColumnOwnerImagePointerStore::class, new Definition(ColumnOwnerImagePointerStore::class, [new Reference('doctrine'), $columns, new Reference(UserKitTransactionRunner::class)]));
            $store = ColumnOwnerImagePointerStore::class;
        } elseif ($store === null) {
            $manager = self::serviceId($config['doctrine']['entity_manager']);
            $container->setDefinition(DbalOwnerImagePointerStore::class, new Definition(DbalOwnerImagePointerStore::class, [new Reference('doctrine'), $manager, new Reference(UserKitTransactionRunner::class)]));
            // House rule LQ: binary collation of kind / subject_id on MySQL / MariaDB.
            $container->setDefinition(PointerCollationListener::class, (new Definition(PointerCollationListener::class, [new Reference('doctrine'), $manager]))
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));
        }
        $container->setAlias(OwnerImagePointerStoreInterface::class, $store ?? DbalOwnerImagePointerStore::class)->setPublic(true);

        $storage = self::serviceId($config['storage']);
        if ($storage === null) {
            self::registerStorageKit($container, $kinds);
        }
        $container->setAlias(OwnerImageStorageInterface::class, $storage ?? StorageKitOwnerImageStorage::class)->setPublic(true);

        if (!$container->hasDefinition(CommitQueue::class)) {
            // 1.19.0 (audit QM-D-M1): a replaced picture is deleted after the outermost commit, also without the registry.
            SessionsLoader::registerCommitQueue($container, new Reference('doctrine'));
        }
        $container->setDefinition(OwnerImages::class, (new Definition(OwnerImages::class, [
            new Reference(OwnerImageKinds::class),
            new Reference(OwnerImageAccessInterface::class),
            new Reference(OwnerImagePointerStoreInterface::class),
            new Reference(OwnerImageStorageInterface::class),
            new Reference(OwnerImageSubjects::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            self::serviceId($config['doctrine']['entity_manager']),
            new Reference(CommitQueue::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setAlias(OwnerImagesInterface::class, OwnerImages::class)->setPublic(true);
        // 1.23.0 (DDD-host checklist B8): access checks by subject id.
        $container->setDefinition(OwnerImageAccessChecker::class, new Definition(OwnerImageAccessChecker::class, [
            new Reference(OwnerImageKinds::class),
            new Reference(OwnerImageAccessInterface::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(OwnerImageAccessCheckerInterface::class, OwnerImageAccessChecker::class)->setPublic(true);

        // 1.20: the AuditKit bridge is registered by OwnerImageAuditPass once AuditKit's services are known.
        $audit = $config['audit'] ?? ['enabled' => false, 'user_id_field' => null, 'actions' => OwnerImagesConfiguration::DEFAULT_AUDIT_ACTIONS, 'kinds' => []];
        if ($audit['enabled']) {
            $container->setParameter(OwnerImageAuditPass::PARAMETER, ['actions' => self::auditActions($audit, array_keys($config['kinds'])), 'user_id_field' => $audit['user_id_field']]);
        }

        $container->setDefinition(OwnerImageType::class, (new Definition(OwnerImageType::class, [new Reference(OwnerImagesInterface::class)]))->addTag('form.type'));

        if ($twig && class_exists(AbstractExtension::class)) {
            $container->setDefinition(OwnerImageExtension::class, (new Definition(OwnerImageExtension::class, [
                new Reference(OwnerImagesInterface::class),
                new Reference(OwnerImageSubjects::class),
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $config['twig']['sizes'],
                (string) $config['avatar_kind'],
            ]))->addTag('twig.extension')->addTag('kernel.reset', ['method' => 'reset']));
            $listener = (new Definition(OwnerImageMemoListener::class, [new Reference(OwnerImageExtension::class)]))
                ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'priority' => 4096])
                ->addTag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'priority' => -4096]);
            if (class_exists(WorkerMessageHandledEvent::class)) {
                $listener->addTag('kernel.event_listener', ['event' => WorkerMessageHandledEvent::class])
                    ->addTag('kernel.event_listener', ['event' => WorkerMessageFailedEvent::class]);
            }
            $container->setDefinition(OwnerImageMemoListener::class, $listener);
        }

        if ($personalData) {
            $container->setDefinition(OwnerImageEraser::class, (new Definition(OwnerImageEraser::class, [new Reference(OwnerImagesInterface::class), new Reference(OwnerImageSubjects::class)]))
                ->addTag(NowoUserKitExtension::TAG_ERASER, ['priority' => -140]));
            $container->setDefinition(OwnerImageExportProvider::class, (new Definition(OwnerImageExportProvider::class, [new Reference(OwnerImagesInterface::class), new Reference(OwnerImageSubjects::class)]))
                ->addTag(NowoUserKitExtension::TAG_EXPORT_PROVIDER, ['priority' => -140]));
        }
    }

    /**
     * The StorageKit adapter and one square-only file context per kind.
     *
     * @param array<string, Definition> $kinds
     */
    private static function registerStorageKit(ContainerBuilder $container, array $kinds): void
    {
        self::assertStorageKit(interface_exists(SquareImageUploaderInterface::class) && interface_exists(SquareImageContextInterface::class));
        $container->setDefinition(StorageKitOwnerImageStorage::class, new Definition(StorageKitOwnerImageStorage::class, [
            new Reference(SquareImageUploaderInterface::class),
            new Reference(FileReferencesInterface::class),
            new Reference(FileRemoverInterface::class),
            new Reference(FileUrls::class),
            new Reference(FileUris::class),
            new Reference(StorageProfiles::class),
            new Reference(SquareImageProcessor::class),
            new Reference(StorageEntityManager::class),
            new Reference(CommitQueue::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        foreach ($kinds as $name => $kind) {
            $container->setDefinition(self::CONTEXT_PREFIX . $name, (new Definition(OwnerImageFileContext::class, [
                $kind,
                new Reference(OwnerImageAccessInterface::class),
                new Reference(OwnerImagePointerStoreInterface::class),
            ]))->addTag(FileContextInterface::TAG));
        }
    }

    /**
     * Fails the compilation when the default storage is asked for without StorageKitBundle 1.6+.
     *
     * @throws InvalidConfigurationException
     */
    public static function assertStorageKit(bool $available): void
    {
        if (!$available) {
            throw new InvalidConfigurationException('nowo_user_kit.owner_images needs nowo-tech/storage-kit-bundle 1.6+ (composer require nowo-tech/storage-kit-bundle), or your own owner_images.storage service.');
        }
    }

    /**
     * The actions of each kind, `{kind}` replaced, checked against AuditKit's action column.
     *
     * @param array{actions: array{uploaded: string, replaced: string, removed: string}, kinds: array<string, array{uploaded: string|null, replaced: string|null, removed: string|null}>} $audit
     * @param list<int|string> $kinds
     *
     * @throws InvalidConfigurationException
     *
     * @return array<string, array{uploaded: string, replaced: string, removed: string}>
     */
    public static function auditActions(array $audit, array $kinds): array
    {
        foreach (array_keys($audit['kinds']) as $name) {
            if (!in_array($name, $kinds, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.audit.kinds: "%s" is not a configured kind.', $name));
            }
        }
        $actions = [];
        foreach ($kinds as $name) {
            $name = (string) $name;
            foreach ($audit['actions'] as $change => $default) {
                $action = str_replace(OwnerImageAuditListener::KIND_PLACEHOLDER, $name, $audit['kinds'][$name][$change] ?? $default);
                if (preg_match(OwnerImagesConfiguration::ACTION_PATTERN, $action) !== 1) {
                    throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.audit: the %s action of kind "%s" ("%s") must be 1-48 characters [A-Za-z0-9_.:-] once {kind} is replaced.', $change, $name, $action));
                }
                $actions[$name][$change] = $action;
            }
        }

        return $actions;
    }

    private static function serviceId(?string $id): ?string
    {
        return is_string($id) && $id !== '' ? $id : null;
    }
}
