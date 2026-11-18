<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\OwnerImage;

use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\UserKitBundle\DependencyInjection\Compiler\OwnerImageAuditPass;
use Nowo\UserKitBundle\DependencyInjection\Configuration;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\OwnerImage\Audit\OwnerImageAuditListener;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\DbalOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\IdActionLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\RelationActionLog;
use Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures\ColumnHost;
use Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures\ColumnHostChild;
use Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures\SubjectUser;
use Nowo\UserKitBundle\Tests\Unit\OwnerImage\Fixtures\XmlMappedHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function str_repeat;

/**
 * 1.20.0: `owner_images.pointer_store: column` (kinds.*.pointer, checked at build time) and `owner_images.audit` (the
 * AuditKit bridge, registered by {@see OwnerImageAuditPass}).
 */
final class OwnerImageAuditAndColumnConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $ownerImages
     */
    private function load(array $ownerImages): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoUserKitExtension())->load([['user_class' => ColumnHost::class, 'owner_images' => ['enabled' => true] + $ownerImages]], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $ownerImages
     *
     * @return array<string, mixed>
     */
    private function process(array $ownerImages): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['owner_images' => $ownerImages]])['owner_images'];
    }

    public function testDefaultsKeepTheBundleTableAndNoAudit(): void
    {
        $config = $this->process([]);
        self::assertSame(['enabled' => false, 'user_id_field' => null, 'actions' => ['uploaded' => 'owner_image.uploaded', 'replaced' => 'owner_image.replaced', 'removed' => 'owner_image.removed'], 'kinds' => []], $config['audit']);
        self::assertTrue($this->process(['audit' => true])['audit']['enabled']);

        $container = $this->load([]);
        self::assertSame(DbalOwnerImagePointerStore::class, (string) $container->getAlias(OwnerImagePointerStoreInterface::class));
        self::assertFalse($container->hasDefinition(ColumnOwnerImagePointerStore::class));
        self::assertFalse($container->hasParameter(OwnerImageAuditPass::PARAMETER));
    }

    public function testTheColumnStoreIsWiredPerKind(): void
    {
        $container = $this->load([
            'pointer_store' => 'column',
            'kinds'         => [
                'avatar' => ['subject' => 'user', 'pointer' => ['entity' => '\\' . ColumnHostChild::class, 'field' => 'logo']],
                'logo'   => ['pointer' => ['entity' => XmlMappedHost::class, 'field' => 'logo', 'subject_field' => 'uuid']],
            ],
        ]);
        self::assertSame(ColumnOwnerImagePointerStore::class, (string) $container->getAlias(OwnerImagePointerStoreInterface::class));
        self::assertSame([
            'avatar' => ['entity' => ColumnHostChild::class, 'field' => 'logo', 'subject_field' => null],
            'logo'   => ['entity' => XmlMappedHost::class, 'field' => 'logo', 'subject_field' => 'uuid'],
        ], $container->getDefinition(ColumnOwnerImagePointerStore::class)->getArgument(1));
        self::assertFalse($container->hasDefinition(DbalOwnerImagePointerStore::class));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badColumns(): iterable
    {
        yield 'a pointer without the column store' => [['kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'logo']]]], 'kinds.avatar.pointer is only used with owner_images.pointer_store: column'];
        yield 'a kind without a pointer' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user']]], 'kind "avatar" has no pointer'];
        yield 'a missing class' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => 'App\\Missing', 'field' => 'logo']]]], 'class "App\\Missing" does not exist'];
        yield 'a missing field' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'nope']]]], 'pointer.field: "' . ColumnHost::class . '" has no property "nope"'];
        yield 'a missing subject field' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'logo', 'subject_field' => 'uuid']]]], 'pointer.subject_field: "' . ColumnHost::class . '" has no property "uuid"'];
        yield 'an unmapped property' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'plain']]]], '$plain is not a mapped column'];
        yield 'one column for two kinds' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'logo']], 'logo' => ['pointer' => ['entity' => ColumnHost::class, 'field' => 'logo']]]], '"avatar" and "logo" point at the same column'];
        yield 'a bad entity name' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => 'App Entity', 'field' => 'logo']]]], 'pointer.entity must be a fully qualified class name'];
        yield 'a bad field name' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'lo-go']]]], 'pointer.field must be a field name'];
        yield 'a bad subject field name' => [['pointer_store' => 'column', 'kinds' => ['avatar' => ['subject' => 'user', 'pointer' => ['entity' => ColumnHost::class, 'field' => 'logo', 'subject_field' => '1d']]]], 'pointer.subject_field must be a field name'];
        yield 'an audit action of an unknown kind' => [['audit' => ['enabled' => true, 'kinds' => ['org' => ['removed' => 'x']]]], 'audit.kinds: "org" is not a configured kind'];
        yield 'a too long audit action' => [['audit' => ['enabled' => true, 'actions' => ['uploaded' => str_repeat('a', 44) . '.{kind}']]], 'the uploaded action of kind "avatar"'];
        yield 'a bad audit action' => [['audit' => ['enabled' => true, 'actions' => ['removed' => 'image removed']]], 'the removed action of kind "avatar" ("image removed")'];
        yield 'a bad user id field' => [['audit' => ['enabled' => true, 'user_id_field' => 'u.uuid']], 'audit.user_id_field must be a field name'];
    }

    /**
     * @param array<string, mixed> $ownerImages
     */
    #[DataProvider('badColumns')]
    public function testBadConfigurationsFailTheBuild(array $ownerImages, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->load($ownerImages);
    }

    public function testTheAuditActionsArePerKind(): void
    {
        $container = $this->load([
            'kinds' => ['avatar' => ['subject' => 'user'], 'org_logo' => []],
            'audit' => ['enabled' => true, 'user_id_field' => 'uuid', 'actions' => ['uploaded' => 'img.{kind}.up'], 'kinds' => ['org_logo' => ['removed' => 'organization.logo_removed']]],
        ]);
        self::assertSame(['actions' => [
            'avatar'   => ['uploaded' => 'img.avatar.up', 'replaced' => 'owner_image.replaced', 'removed' => 'owner_image.removed'],
            'org_logo' => ['uploaded' => 'img.org_logo.up', 'replaced' => 'owner_image.replaced', 'removed' => 'organization.logo_removed'],
        ], 'user_id_field' => 'uuid'], $container->getParameter(OwnerImageAuditPass::PARAMETER));
    }

    private function audited(?string $logClass, ?string $userIdField = null, mixed $userClass = ColumnHost::class, bool $recorder = true, ?string $flushMode = 'isolated'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($flushMode !== null) {
            $container->setParameter(OwnerImageAuditPass::FLUSH_MODE_PARAMETER, $flushMode);
        }
        $container->setParameter(OwnerImageAuditPass::PARAMETER, ['actions' => ['avatar' => ['uploaded' => 'u', 'replaced' => 'r', 'removed' => 'd']], 'user_id_field' => $userIdField]);
        if ($recorder) {
            $container->setDefinition(ActionLogIdRecorderInterface::class, new Definition(stdClass::class));
        }
        if ($logClass !== null) {
            $container->setParameter(OwnerImageAuditPass::LOG_CLASS_PARAMETER, $logClass);
        }
        $container->setDefinition(OwnerImageSubjects::class, new Definition(OwnerImageSubjects::class, [null, $userClass]));
        (new OwnerImageAuditPass())->process($container);

        return $container;
    }

    public function testThePassRegistersTheListenerOnlyWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        (new OwnerImageAuditPass())->process($container);
        self::assertFalse($container->hasDefinition(OwnerImageAuditListener::class));

        $listener = $this->audited(IdActionLog::class, 'uuid', SubjectUser::class)->getDefinition(OwnerImageAuditListener::class);
        self::assertSame([['event' => OwnerImageChangedEvent::class]], $listener->getTag('kernel.event_listener'));
        self::assertSame([null, null], [$listener->getArgument(4), $listener->getArgument(5)], 'an id log stores subject ids as they are');

        $listener = $this->audited(RelationActionLog::class, 'uuid', SubjectUser::class)->getDefinition(OwnerImageAuditListener::class);
        self::assertSame(['uuid', RelationActionLog::class], [$listener->getArgument(4), $listener->getArgument(5)]);

        $listener = $this->audited(RelationActionLog::class)->getDefinition(OwnerImageAuditListener::class);
        self::assertSame([null, RelationActionLog::class], [$listener->getArgument(4), $listener->getArgument(5)], 'identifiers are the subject ids');
    }

    /**
     * @return iterable<string, array{string|null, mixed, bool, string|null, string}>
     */
    public static function badAudits(): iterable
    {
        yield 'no AuditKit action log' => [IdActionLog::class, ColumnHost::class, false, 'isolated', 'needs nowo-tech/audit-kit-bundle'];
        yield 'relations and own subject ids' => [RelationActionLog::class, SubjectUser::class, true, 'isolated', 'set owner_images.audit.user_id_field'];
        yield 'relations and no user class' => [RelationActionLog::class, null, true, 'isolated', 'set owner_images.audit.user_id_field'];
        yield 'the host flush mode' => [IdActionLog::class, ColumnHost::class, true, 'host', 'needs nowo_audit_kit.action_log.flush_mode: isolated'];
        yield 'no flush mode' => [IdActionLog::class, ColumnHost::class, true, null, 'needs nowo_audit_kit.action_log.flush_mode: isolated'];
    }

    #[DataProvider('badAudits')]
    public function testThePassRefusesWhatCannotBeRecorded(?string $logClass, mixed $userClass, bool $recorder, ?string $flushMode, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->audited($logClass, null, $userClass, $recorder, $flushMode);
    }
}
