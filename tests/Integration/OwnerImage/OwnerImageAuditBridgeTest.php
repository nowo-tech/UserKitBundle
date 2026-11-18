<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\UserKitBundle\OwnerImage\Audit\OwnerImageAuditListener;
use Nowo\UserKitBundle\OwnerImage\OwnerImageChangedEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\AuditedUser;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\IdActionLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\AuditLog\RelationActionLog;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Organizations;
use stdClass;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function is_string;
use function json_decode;
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
        $this->bootAudit(['enabled' => true, 'kinds' => ['org_logo' => ['removed' => 'organization.{kind}_removed']]], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => IdActionLog::class]]);
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
        $this->bootAudit(['enabled' => true, 'user_id_field' => 'handle'], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => RelationActionLog::class]]);
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

    public function testAMisconfiguredUserFieldFailsLoudly(): void
    {
        $this->bootAudit(['enabled' => true, 'user_id_field' => 'handle'], ['user_class' => AuditedUser::class, 'action_log' => ['entity_class' => RelationActionLog::class]]);
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $recorder = $this->service(ActionLogIdRecorderInterface::class);
        $kinds    = $this->service(OwnerImageKinds::class);
        $actions  = ['avatar' => ['uploaded' => 'u', 'replaced' => 'r', 'removed' => 'd']];
        $event    = new OwnerImageChangedEvent('avatar', 'alice', 'f', null, 'alice');

        foreach ([[stdClass::class, 'handle', 'no Doctrine entity manager maps'], [RelationActionLog::class, 'nope', 'user_id_field: "nope" is not a mapped field']] as [$logClass, $field, $message]) {
            try {
                (new OwnerImageAuditListener($recorder, $kinds, $actions, $doctrine, $field, $logClass))($event);
                self::fail('accepted');
            } catch (LogicException $e) {
                self::assertStringContainsStringIgnoringCase($message, $e->getMessage());
            }
        }
    }
}
