<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\UserKitBundle\OwnerImage\Entity\OwnerImagePointer;
use Nowo\UserKitBundle\OwnerImage\OwnerImageRefused;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnOwnerImagePointerStore;
use Nowo\UserKitBundle\OwnerImage\Pointer\OwnerImagePointerStoreInterface;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\User;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\Organization;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Organizations;
use Nowo\UserKitBundle\Transaction\UserKitConflict;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Throwable;

use function getenv;
use function str_repeat;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.20.0 `owner_images.pointer_store: column`: each kind's pointer in a column of the owner's row — the user's
 * `avatarFileId` (subject = the identifier) and an organization's logos (subject = its public `uuid`, no unique key) or
 * badge (subject = its integer identifier). Identity-map aware (a managed entity answers and gets the written value with
 * no pending change), never another owner's row (house rule LQ on a folding collation, two rows of one subject), no
 * bundle table. Runs on SQLite, or on `USER_KIT_TEST_DB_URL` (MySQL, MariaDB and PostgreSQL in CI).
 */
final class ColumnPointerStoreTest extends OwnerImagesKernelTestCase
{
    /** @var array<string, mixed> */
    public const CONFIG = ['owner_images' => [
        'pointer_store' => 'column',
        'access'        => Organizations::class,
        'kinds'         => [
            'avatar'        => ['subject' => 'user', 'shape' => 'circle', 'pointer' => ['entity' => User::class, 'field' => 'avatarFileId']],
            'org_logo'      => ['pointer' => ['entity' => '\\' . Organization::class, 'field' => 'logoFileId', 'subject_field' => 'uuid']],
            'org_logo_dark' => ['pointer' => ['entity' => Organization::class, 'field' => 'logoDarkFileId', 'subject_field' => 'uuid']],
            'org_badge'     => ['pointer' => ['entity' => Organization::class, 'field' => 'badgeFileId']],
        ],
    ]];

    private function bootColumns(): void
    {
        $this->boot(self::CONFIG, static function (ContainerConfigurator $container): void {
            $container->services()->set(Organizations::class)->args([service(OwnerImageSubjects::class)])->public();
        });
        $em = $this->em();
        foreach (['org-1', 'org-2'] as $uuid) {
            $em->persist(new Organization($uuid));
        }
        $em->flush();
        $em->clear();
    }

    private function images(): OwnerImagesInterface
    {
        return $this->service(OwnerImagesInterface::class);
    }

    private function store(): ColumnOwnerImagePointerStore
    {
        $store = $this->service(OwnerImagePointerStoreInterface::class);
        self::assertInstanceOf(ColumnOwnerImagePointerStore::class, $store);

        return $store;
    }

    private function column(string $sql, string $id): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, [$id]);
    }

    private function organization(string $uuid): Organization
    {
        return $this->em()->getRepository(Organization::class)->findOneBy(['uuid' => $uuid]) ?? self::fail('no organization ' . $uuid);
    }

    public function testAUsersAvatarLivesOnTheUserRow(): void
    {
        $this->bootColumns();
        $em       = $this->em();
        $alice    = $this->user('alice');
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        self::assertNull($doctrine->getManagerForClass(OwnerImagePointer::class), 'no bundle table is mapped');

        $first = $this->images()->upload($alice, 'avatar', 'alice', self::png(), 'me.png');
        self::assertSame($first->fileId, $this->column('SELECT avatar_file_id FROM user_kit_test_user WHERE username = ?', 'alice'));
        self::assertSame($first->fileId, $alice->getAvatarFileId(), 'the managed user sees the new picture');
        $em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $em->getUnitOfWork()->getEntityChangeSet($alice), 'and the next flush writes nothing for it');
        self::assertSame(['alice' => $first->fileId], $this->images()->fileIds('avatar', ['alice', 'bob', 'nobody']));

        $second = $this->images()->upload($alice, 'avatar', 'alice', self::png(40, 40), 'me2.png');
        self::assertSame($second->fileId, $alice->getAvatarFileId());
        self::assertCount(3, $this->buckets()->keys(), 'the replaced picture is gone');
        self::assertNull($this->column('SELECT avatar_file_id FROM user_kit_test_user WHERE username = ?', 'bob'));

        $em->clear(); // a write unit may clear the manager between attempts: nothing is held
        self::assertTrue($this->images()->remove($this->user('alice'), 'avatar', 'alice'));
        self::assertFalse($this->images()->remove($this->user('alice'), 'avatar', 'alice'));
        self::assertNull($this->column('SELECT avatar_file_id FROM user_kit_test_user WHERE username = ?', 'alice'));
        self::assertNull($this->user('alice')->getAvatarFileId());
        self::assertSame([], $this->buckets()->keys());
    }

    public function testOrganizationLogosAreFoundByTheirPublicUuid(): void
    {
        $this->bootColumns();
        $alice = $this->user('alice');
        $bob   = $this->user('bob');

        $light = $this->images()->upload($alice, 'org_logo', 'org-1', self::png(), 'logo.png');
        $dark  = $this->images()->upload($alice, 'org_logo_dark', 'org-1', self::png(), 'dark.png');
        try {
            $this->images()->upload($bob, 'org_logo', 'org-1', self::png(), 'bob.png');
            self::fail('bob changed org-1');
        } catch (OwnerImageRefused $e) {
            self::assertSame(OwnerImageRefused::FORBIDDEN, $e->reason);
        }
        self::assertSame([$light->fileId, $dark->fileId], [$this->column('SELECT logo_file_id FROM user_kit_test_organization WHERE uuid = ?', 'org-1'), $this->column('SELECT logo_dark_file_id FROM user_kit_test_organization WHERE uuid = ?', 'org-1')]);
        self::assertNull($this->column('SELECT logo_file_id FROM user_kit_test_organization WHERE uuid = ?', 'org-2'));

        // A managed organization answers from the identity map (what the request sees), the rest from the database.
        $this->em()->clear();
        $managed = $this->organization('org-1');
        $managed->setLogoFileId('in-memory');
        self::assertSame(['org-1' => 'in-memory'], $this->images()->fileIds('org_logo', ['org-1', 'org-2']));
        $this->em()->clear();
        $proxy = $this->em()->getReference(Organization::class, $this->organizationId('org-1'));
        self::assertNotNull($proxy);
        self::assertSame(['org-1' => $light->fileId], $this->images()->fileIds('org_logo', ['org-1']));
        self::assertTrue($this->em()->getUnitOfWork()->isUninitializedObject($proxy), 'a lazy organization is not loaded to read its logo');

        self::assertTrue($this->images()->remove($alice, 'org_logo', 'org-1'));
        self::assertSame(['org-1' => $dark->fileId], $this->images()->fileIds('org_logo_dark', ['org-1']));
        self::assertSame([], $this->images()->fileIds('org_logo', ['org-1']));
    }

    private function organizationId(string $uuid): int
    {
        return (int) $this->column('SELECT id FROM user_kit_test_organization WHERE uuid = ?', $uuid);
    }

    /**
     * House rule LQ: on MySQL / MariaDB the host's `uuid` column folds case (utf8mb4_unicode_ci); `ORG-1` neither reads
     * nor writes `org-1`'s logo. SQLite and PostgreSQL compare exactly.
     */
    public function testALookalikeSubjectNeverReachesAnotherOwnersColumn(): void
    {
        $this->bootColumns();
        $store = $this->store();
        self::assertNull($store->replace('org_logo', 'org-1', 'file-1'));
        try {
            $store->set('org_logo', 'ORG-1', 'evil');
            self::fail('a look-alike was written');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($store->replace('org_logo', 'ORG-1', null));
        self::assertSame([], $store->find('org_logo', ['ORG-1', 'Org-1']));
        self::assertSame(['org-1' => 'file-1'], $store->find('org_logo', ['org-1', 'ORG-1']));
        self::assertSame('file-1', $store->replace('org_logo', 'org-1', 'file-1'), 'the same file: nothing to write');
        self::assertSame('file-1', $store->replace('org_logo', 'org-1', 'file-2'));
        self::assertSame(['org-1' => 'file-2'], $store->find('org_logo', ['org-1']));
    }

    public function testTwoRowsOfOneSubjectWriteNothing(): void
    {
        $this->bootColumns();
        $em = $this->em();
        $em->persist(new Organization('org-1'));
        $em->flush();
        $em->clear();
        $store = $this->store();
        try {
            $store->set('org_logo', 'org-1', 'file-1');
            self::fail('one of two rows was written');
        } catch (UserKitConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_kit_test_organization WHERE logo_file_id IS NOT NULL'));
        $em->getConnection()->executeStatement("UPDATE user_kit_test_organization SET logo_file_id = 'file-x' WHERE id = ?", [$this->organizationId('org-1')]);
        self::assertSame([], $store->find('org_logo', ['org-1']), 'one of two rows has an image: still no guess');
        $em->getConnection()->executeStatement("UPDATE user_kit_test_organization SET logo_file_id = 'file-x' WHERE uuid = 'org-1'");
        self::assertSame([], $store->find('org_logo', ['org-1']), 'no guess between two rows');
    }

    public function testIntegerIdentifierSubjects(): void
    {
        $this->bootColumns();
        $store = $this->store();
        $id    = (string) $this->organizationId('org-1');
        $store->set('org_badge', $id, 'badge-1');
        self::assertEquals([$id => 'badge-1'], $store->find('org_badge', [$id, 'abc', '0' . $id, '-1']));
        self::assertSame([], $store->find('org_badge', ['0' . $id]), 'the row of ' . $id . ' is not the subject 0' . $id);
        self::assertSame('badge-1', $this->organization('org-1')->getBadgeFileId());
        try {
            $store->set('org_badge', 'abc', 'badge-2');
            self::fail('a subject without a row was written');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($store->replace('org_badge', 'abc', null));
        self::assertNull($store->replace('org_badge', '0' . $id, null), 'a look-alike integer is another subject');
        self::assertEquals([$id => 'badge-1'], $store->find('org_badge', [$id]));
    }

    public function testMalformedCallsAreRefused(): void
    {
        $this->bootColumns();
        $store = $this->store();
        foreach ([['nope', 'org-1', 'f'], ['org_logo', 'a b', 'f'], ['org_logo', 'org-1', ''], ['org_logo', 'org-1', str_repeat('f', 65)]] as [$kind, $subject, $file]) {
            try {
                $store->set($kind, $subject, $file);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $store->find('nope', ['org-1']));
        self::assertSame([], $store->find('org_logo', ['a b', '']));
    }

    /**
     * The database the suite runs on (CI runs this test on MySQL, MariaDB and PostgreSQL too).
     */
    public function testRunsOnTheConfiguredDatabase(): void
    {
        $this->bootColumns();
        $platform = $this->em()->getConnection()->getDatabasePlatform()::class;
        $expected = match (getenv('USER_KIT_TEST_DB_PLATFORM')) {
            'mysql'      => 'MySQL|MariaDB',
            'postgresql' => 'PostgreSQL',
            default      => 'SQLite',
        };
        self::assertMatchesRegularExpression('/' . $expected . '/', $platform);
        try {
            $this->store()->set('org_logo', 'org-2', 'f');
        } catch (Throwable $e) {
            self::fail($e->getMessage());
        }
        self::assertSame(['org-2' => 'f'], $this->store()->find('org_logo', ['org-2']));
    }
}
