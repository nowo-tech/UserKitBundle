<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\Sessions\Transaction\CommitQueue;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\StorageAuditLog;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Audit QJ-L3: StorageKit's `file_uploaded` / `file_removed` / `file_expired` events of owner image changes made inside
 * a transaction wait for its commit on UserKit's commit queue (`sessions.enabled`), and are dropped on rollback.
 */
final class OwnerImageAuditAfterCommitTest extends OwnerImagesKernelTestCase
{
    private function bootWithAudit(): void
    {
        $this->boot(['sessions' => ['enabled' => true]], static function (ContainerConfigurator $container): void {
            $container->services()->set(StorageAuditLog::class)->public()->tag('kernel.event_listener', ['event' => StorageAuditEvent::class]);
        });
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    private function actions(): array
    {
        return $this->service(StorageAuditLog::class)->actions;
    }

    public function testTheAuditOfAChangeInsideATransactionFollowsItsCommit(): void
    {
        $this->bootWithAudit();
        $images     = $this->service(OwnerImagesInterface::class);
        $commits    = $this->service(CommitQueue::class);
        $connection = $this->em()->getConnection();
        $alice      = $this->user('alice');

        // Outside a transaction StorageKit records at once.
        $images->upload($alice, 'avatar', 'alice', self::png(), 'a.png');
        self::assertSame([StorageAuditEvent::FILE_UPLOADED], $this->actions());

        // Inside one: held until the commit (replace = upload + removal of the previous picture).
        $connection->beginTransaction();
        $images->upload($alice, 'avatar', 'alice', self::png(), 'b.png');
        self::assertSame([StorageAuditEvent::FILE_UPLOADED], $this->actions());
        $connection->commit();
        $commits->release();
        self::assertSame([StorageAuditEvent::FILE_UPLOADED, StorageAuditEvent::FILE_UPLOADED, StorageAuditEvent::FILE_REMOVED], $this->actions());

        // Rolled back: nothing is recorded.
        $connection->beginTransaction();
        $images->upload($alice, 'avatar', 'alice', self::png(), 'c.png');
        $connection->rollBack();
        $commits->release();
        self::assertCount(3, $this->actions());

        // GDPR erasure (its own transaction): file_expired once it committed.
        $eraser = $this->container()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);
        $eraser->erase($this->user('alice'));
        self::assertSame(StorageAuditEvent::FILE_EXPIRED, $this->actions()[3] ?? null);
    }
}
