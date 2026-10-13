<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\PersonalData\ErasureVetoedException;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\GdprUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;

use const DATE_ATOM;

/**
 * GDPR SPI wired in a kernel: tagged providers / erasers / guards, priority order, one transaction.
 */
final class PersonalDataKernelTest extends TestCase
{
    private ?TestKernel $kernel = null;

    private EntityManagerInterface $entityManager;

    private PersonalDataExporter $exporter;

    private PersonalDataEraser $eraser;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel(['personal_data' => ['enabled' => true, 'schema' => 'test-export', 'version' => 2]]);
        $this->kernel->boot();

        $container     = $this->kernel->getContainer();
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $exporter      = $container->get('test.personal_data.exporter');
        $eraser        = $container->get('test.personal_data.eraser');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->assertInstanceOf(PersonalDataExporter::class, $exporter);
        $this->assertInstanceOf(PersonalDataEraser::class, $eraser);

        $this->entityManager = $entityManager;
        $this->exporter      = $exporter;
        $this->eraser        = $eraser;

        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(GdprUser::class)]);
    }

    protected function tearDown(): void
    {
        if ($this->kernel instanceof TestKernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove(dirname($cacheDir));
            $this->kernel = null;
        }
    }

    public function testExportDocument(): void
    {
        $user = $this->persistUser('u1');

        $document = $this->exporter->export($user);

        $this->assertSame('test-export', $document['schema']);
        $this->assertSame(2, $document['version']);
        $this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $document['exported_at']));
        $this->assertSame(['account', 'account_status', 'last_activity', 'ui_preferences', 'product_tour'], array_keys($document['sections']));
        $this->assertSame(['email' => 'u1@example.com'], $document['sections']['account']);
        $this->assertSame(['enabled' => true], $document['sections']['account_status']);
        $this->assertSame(['last_activity_at' => '2026-10-01T08:00:00+00:00'], $document['sections']['last_activity']);
        $this->assertSame('dark', $document['sections']['ui_preferences']['preferred_theme']);
        $this->assertSame(['seen_at' => null, 'seen_pages' => ['dashboard']], $document['sections']['product_tour']);
        $this->assertJson($this->exporter->exportJson($user));
    }

    public function testEraseRunsErasersInPriorityOrderAndPersists(): void
    {
        $user = $this->persistUser('u2');

        $this->assertSame([], $this->eraser->check($user));
        $this->assertSame(['account', 'last_activity', 'ui_preferences', 'product_tour'], $this->eraser->erase($user, $user));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(GdprUser::class, 'u2');
        $this->assertInstanceOf(GdprUser::class, $reloaded);
        $this->assertSame('anonymized-u2@invalid.local', $reloaded->getEmail());
        $this->assertFalse($reloaded->isEnabled());
        $this->assertNull($reloaded->getLastActivityAt());
        $this->assertNull($reloaded->getUiPreferences()->getPreferredThemeRaw());
        $this->assertSame([], $reloaded->getProductTourProgress()->getProductTourSeenPages());
    }

    public function testGuardVetoesBeforeAnythingIsErased(): void
    {
        $user = $this->persistUser('admin');

        $vetoes = $this->eraser->check($user);
        $this->assertCount(1, $vetoes);
        $this->assertSame('last_admin', $vetoes[0]->reason);

        try {
            $this->eraser->erase($user);
            $this->fail('Expected a veto.');
        } catch (ErasureVetoedException $exception) {
            $this->assertTrue($exception->hasReason('last_admin'));
        }

        $this->assertSame('admin@example.com', $user->getEmail());
    }

    public function testFailingEraserRollsBackTheTransaction(): void
    {
        $this->persistUser('u3');

        try {
            $this->eraser->erase($this->entityManager->find(GdprUser::class, 'u3') ?? throw new RuntimeException(), null, ['fail' => true]);
            $this->fail('Expected the eraser to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $email = $this->entityManager->getConnection()->fetchOne('SELECT email FROM user_kit_gdpr_user WHERE id = ?', ['u3']);
        $this->assertSame('u3@example.com', $email);
        $this->assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    private function persistUser(string $id): GdprUser
    {
        $user = new GdprUser($id, $id . '@example.com');
        $user->setLastActivityAt(new DateTimeImmutable('2026-10-01T08:00:00+00:00'));
        $user->getUiPreferences()->setPreferredTheme('dark');
        $user->getProductTourProgress()->markPageSeen('dashboard');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
