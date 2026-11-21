<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\UserKitBundle\OwnerImage\Audit\OwnerImageAuditListener;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\AuditedUser;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\IdActionLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\RelationActionLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\FailingRecorder;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Organizations;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\RecordingLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function is_string;
use function json_decode;
use function json_encode;
use function str_contains;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.20.0 `owner_images.audit`: one AuditKit action log row per uploaded / replaced / removed owner image, recorded by
 * ids (no entity of the application's unit of work), so a cleared entity manager between attempts changes nothing and
 * a rolled-back change leaves no row. With an action log of user relations, `user_id_field` turns subject ids into the
 * users' identifiers (byte for byte; none when ambiguous).
 */
final class OwnerImageAuditBridgeTest extends OwnerImagesKernelTestCase
{
    /**
     * @param array<string, mixed> $audit
     * @param array<string, mixed> $auditKit
     */
    private function bootAudit(array $audit, array $auditKit): void
    {
        $this->boot(['owner_images' => [
            'access' => Organizations::class,
            'kinds'  => ['avatar' => ['subject' => 'user'], 'org_logo' => []],
            'audit'  => $audit,
        ]], static function (ContainerConfigurator $container): void {
            $container->services()->set(Organizations::class)->args([service(OwnerImageSubjects::class)])->public();
        }, auditKit: $auditKit);
    }

    private function images(): OwnerImagesInterface
    {
        return $this->service(OwnerImagesInterface::class);
    }

    /**
     * @return list<array{action: string, actor: string|null, subject: string|null, context: array<string, mixed>}>
     */
    private function rows(string $table): array
    {
        return array_map(static function (array $row): array {
            $context = is_string($row['context']) ? json_decode($row['context'], true) : [];

            return [
                'action'  => (string) $row['action'],
                'actor'   => $row['actor_id'] === null ? null : (string) $row['actor_id'],
                'subject' => $row['subject_id'] === null ? null : (string) $row['subject_id'],
                'context' => (array) $context,
            ];
        }, $this->em()->getConnection()->fetchAllAssociative('SELECT action, actor_id, subject_id, context FROM ' . $table . ' ORDER BY id'));
    }

    public function testEveryChangeIsRecordedByIds(): void
    {
        $this->bootAudit(['enabled' => true, 'kinds' => ['org_logo' => ['removed' => 'organization.{kind}_removed']]], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => IdActionLog::class, 'flush_mode' => 'isolated']]);
        $images = $this->images();

        $first = $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'a.png');
        $this->em()->clear(); // a write unit may clear the manager between attempts: the listener holds no entity
        $second = $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'b.png');
        $logo   = $images->upload($this->user('alice'), 'org_logo', 'org-1', self::png(), 'logo.png');
        self::assertTrue($images->remove($this->user('alice'), 'org_logo', 'org-1'));
        self::assertTrue($images->remove($this->user('alice'), 'avatar', 'alice'));

        // Rolled back with the change.
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'c.png');
        $connection->rollBack();
        // A kind the bridge does not know records nothing.
        $this->service(EventDispatcherInterface::class)->dispatch(new OwnerImageChangedEvent('nope', 'alice', 'f', null, 'alice'));

        $rows = $this->rows('user_kit_test_id_action_log');
        self::assertSame(
            ['owner_image.uploaded', 'owner_image.replaced', 'owner_image.uploaded', 'organization.org_logo_removed', 'owner_image.removed'],
            array_map(static fn (array $row): string => $row['action'], $rows),
        );
        self::assertSame(['alice', 'alice'], [$rows[0]['actor'], $rows[0]['subject']]);
        self::assertEquals(['kind' => 'avatar', 'subject_id' => 'alice', 'actor_id' => 'alice', 'file' => $second->fileId, 'previous_file' => $first->fileId], $rows[1]['context']);
        self::assertSame(['alice', null], [$rows[2]['actor'], $rows[2]['subject']], 'an organization is no user: its id is only in the context');
        self::assertEquals(['kind' => 'org_logo', 'subject_id' => 'org-1', 'actor_id' => 'alice', 'file' => $logo->fileId, 'previous_file' => null], $rows[2]['context']);
        self::assertSame([null, $logo->fileId], [$rows[3]['context']['file'], $rows[3]['context']['previous_file']]);
    }

    public function testAnActionLogOfUserRelationsGetsTheUsersIdentifiers(): void
    {
        $this->bootAudit(['enabled' => true, 'user_id_field' => 'handle'], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => RelationActionLog::class, 'flush_mode' => 'isolated']]);
        $em = $this->em();
        $em->persist($alice = new AuditedUser('alice'));
        $em->persist(new AuditedUser('bob'));
        $em->persist(new AuditedUser('bob'));
        $em->persist(new AuditedUser('ALICE'));
        $em->flush();
        $em->clear();

        $this->images()->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'a.png');
        $this->images()->upload($this->user('bob'), 'avatar', 'bob', self::png(), 'b.png');

        $rows = $this->rows('user_kit_test_relation_action_log');
        self::assertCount(2, $rows);
        self::assertSame([(string) $alice->getId(), (string) $alice->getId()], [$rows[0]['actor'], $rows[0]['subject']], 'alice, not her look-alike ALICE');
        self::assertSame([null, null], [$rows[1]['actor'], $rows[1]['subject']], 'two users hold "bob": no link rather than a guess');
        self::assertSame('bob', $rows[1]['context']['actor_id']);
    }

    /**
     * Audit TC-L4: a failed row (here a misconfigured user_id_field, or a recorder that fails) never turns the done
     * change into an error; a warning names the exception class only.
     */
    public function testAFailedRowIsLoggedNotThrown(): void
    {
        $this->bootAudit(['enabled' => true, 'user_id_field' => 'handle'], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => RelationActionLog::class, 'flush_mode' => 'isolated']]);
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $recorder = $this->service(ActionLogIdRecorderInterface::class);
        $kinds    = $this->service(OwnerImageKinds::class);
        $actions  = ['avatar' => ['uploaded' => 'u', 'replaced' => 'r', 'removed' => 'd']];
        $event    = new OwnerImageChangedEvent('avatar', 'alice', 'f', null, 'alice');
        $logger   = new RecordingLogger();

        (new OwnerImageAuditListener($recorder, $kinds, $actions, $doctrine, 'handle', stdClass::class, $logger))($event);
        (new OwnerImageAuditListener($recorder, $kinds, $actions, $doctrine, 'nope', RelationActionLog::class, $logger))($event);
        $failing = $this->createMock(ActionLogIdRecorderInterface::class);
        $failing->method('recordIdsAndFlush')->willThrowException(new RuntimeException('SQLSTATE secret-id'));
        (new OwnerImageAuditListener($failing, $kinds, $actions, null, null, null, $logger))($event);
        (new OwnerImageAuditListener($failing, $kinds, $actions))($event); // no logger: still not thrown
        $transient = $this->createMock(ActionLogIdRecorderInterface::class);
        $transient->method('recordIdsAndFlush')->willThrowException(new TransientStorageFailure());
        try {
            (new OwnerImageAuditListener($transient, $kinds, $actions, null, null, null, $logger))($event);
            self::fail('a transient failure was swallowed');
        } catch (TransientStorageFailure) {
            self::addToAssertionCount(1);
        }

        $records = $logger->take();
        self::assertSame([LogicException::class, LogicException::class, RuntimeException::class], array_map(static fn (array $r): mixed => $r['context']['class'] ?? null, $records));
        self::assertSame(['warning'], array_values(array_unique(array_map(static fn (array $r): mixed => $r['level'], $records))));
        self::assertStringNotContainsString('secret-id', (string) json_encode($records), 'only the class is logged');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM user_kit_test_relation_action_log'));
    }

    /**
     * Audit TC-L5: a deadlock while recording the row is rethrown (on MySQL it rolled back the whole transaction, the
     * pointer write included): the caller rolls back, the replaced picture is not deleted and the pointer stays on it.
     * Any other failure is swallowed: the change stands.
     */
    public function testATransientFailureIsRethrownAndTheChangeRollsBack(): void
    {
        $this->boot(['sessions' => ['enabled' => true], 'owner_images' => ['audit' => true]], static function (ContainerConfigurator $container): void {
            $container->services()->set(FailingRecorder::class)->public();
            $container->services()->alias(ActionLogIdRecorderInterface::class, FailingRecorder::class)->public();
        }, auditKit: ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => IdActionLog::class, 'flush_mode' => 'isolated']]);
        $images     = $this->images();
        $recorder   = $this->service(FailingRecorder::class);
        $commits    = $this->service(CommitQueue::class);
        $connection = $this->em()->getConnection();
        $first      = $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'a.png');
        self::assertSame(1, $recorder->recorded);

        $recorder->failure = new DeadlockException(new class('deadlock', null, 1213) extends AbstractException {
        }, null);
        $connection->beginTransaction();
        $thrown = null;
        try {
            $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'b.png');
        } catch (DeadlockException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(DeadlockException::class, $thrown, 'the deadlock reaches the caller');
        $connection->rollBack();
        $commits->release();
        self::assertSame(['alice' => $first->fileId], $images->fileIds('avatar', ['alice']));
        self::assertNotSame([], array_filter($this->buckets()->keys(), static fn (string $key): bool => str_contains($key, $first->fileId)), 'the previous picture still exists');

        $recorder->failure = new RuntimeException('not transient');
        $third             = $images->upload($this->user('alice'), 'avatar', 'alice', self::png(), 'c.png');
        self::assertSame(['alice' => $third->fileId], $images->fileIds('avatar', ['alice']), 'a non-transient failure is swallowed');
    }

    /**
     * Audit TC-M1: the bridge never flushes the application's entity manager — a half-edited entity stays unwritten.
     */
    public function testTheApplicationsPendingChangesAreNotFlushed(): void
    {
        $this->bootAudit(['enabled' => true], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => IdActionLog::class, 'flush_mode' => 'isolated']]);
        $alice = $this->user('alice');
        $alice->setAvatarFileId('half-edited');
        $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'a.png');
        self::assertCount(1, $this->rows('user_kit_test_id_action_log'));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT avatar_file_id FROM user_kit_test_user WHERE username = ?', ['alice']));
    }

    /**
     * Audit TC-M1: AuditKit's flush mode "host" (explicit: the default is isolated since AuditKit 2.0) fails the container build.
     */
    public function testTheHostFlushModeFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('flush_mode: isolated');
        $this->bootAudit(['enabled' => true], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => IdActionLog::class, 'flush_mode' => 'host']]);
    }
}
