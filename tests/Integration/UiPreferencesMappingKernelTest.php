<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\HostPreferencesUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\PreferencesUser;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;

/**
 * Doctrine mapping of the UI preference / product-tour columns on SQLite.
 *
 * The expected column names, types and lengths are the ones Beacon already has in production
 * (`preferred_*`, `product_tour_seen_*`), so adopting the kit needs no migration.
 */
final class UiPreferencesMappingKernelTest extends TestCase
{
    /** column => [type, length, nullable] */
    private const EXPECTED_COLUMNS = [
        'preferred_locale'        => ['string', 8, true],
        'preferred_theme'         => ['string', 8, true],
        'preferred_content_width' => ['string', 8, true],
        'preferred_ui_density'    => ['string', 16, true],
        'preferred_motion'        => ['string', 16, true],
        'preferred_font_scale'    => ['string', 8, true],
        'preferred_contrast'      => ['string', 8, true],
        'preferred_sidebar'       => ['string', 16, true],
        'preferred_footer'        => ['string', 16, true],
        'product_tour_seen_at'    => ['datetime_immutable', null, true],
        'product_tour_seen_pages' => ['json', null, true],
    ];

    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        if ($this->kernel instanceof TestKernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove(dirname($cacheDir));
            $this->kernel = null;
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function entityProvider(): iterable
    {
        yield 'kit embeddables' => [PreferencesUser::class];
        yield 'host embeddable using the traits' => [HostPreferencesUser::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('entityProvider')]
    public function testColumnsMatchTheHostSchema(string $class): void
    {
        $metadata = $this->entityManager()->getClassMetadata($class);

        $actual = [];
        foreach ($metadata->getFieldNames() as $field) {
            $mapping                        = $metadata->getFieldMapping($field);
            $actual[$mapping['columnName']] = [$mapping['type'], $mapping['length'] ?? null, (bool) ($mapping['nullable'] ?? false)];
        }

        foreach (self::EXPECTED_COLUMNS as $column => $expected) {
            $this->assertArrayHasKey($column, $actual, $column);
            $this->assertSame($expected, $actual[$column], $column);
        }
    }

    public function testCreateSchemaSqlHasNoDatabaseDefaults(): void
    {
        $entityManager = $this->entityManager();
        $sql           = implode("\n", (new SchemaTool($entityManager))->getCreateSchemaSql([$entityManager->getClassMetadata(PreferencesUser::class)]));

        $this->assertStringContainsString('preferred_locale VARCHAR(8) DEFAULT NULL', $sql);
        $this->assertStringContainsString('preferred_ui_density VARCHAR(16) DEFAULT NULL', $sql);
        $this->assertStringContainsString('product_tour_seen_at DATETIME DEFAULT NULL', $sql);
        $this->assertStringContainsString('product_tour_seen_pages CLOB DEFAULT NULL', $sql);
    }

    public function testRoundTripKitEmbeddables(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(PreferencesUser::class)]);

        $user = new PreferencesUser('u1');
        $user->getUiPreferences()->setPreferredTheme(Theme::Dark)->setPreferredLocale(' ES ')->setPreferredSidebar('rail');
        $user->getProductTourProgress()->markPageSeen('dashboard');
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(PreferencesUser::class, 'u1');
        $this->assertInstanceOf(PreferencesUser::class, $reloaded);
        $this->assertSame('dark', $reloaded->getUiPreferences()->getPreferredTheme());
        $this->assertSame('es', $reloaded->getUiPreferences()->getPreferredLocale());
        $this->assertSame('rail', $reloaded->getUiPreferences()->getPreferredSidebar());
        $this->assertSame('comfortable', $reloaded->getUiPreferences()->getPreferredUiDensityRaw());
        $this->assertSame(['dashboard'], $reloaded->getProductTourProgress()->getProductTourSeenPages());
        $this->assertNull($reloaded->getProductTourProgress()->getProductTourSeenAt());
    }

    public function testRoundTripHostEmbeddable(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(HostPreferencesUser::class)]);

        $at   = new DateTimeImmutable('2026-10-13 10:00:00');
        $user = new HostPreferencesUser('u2');
        $user->getUiPreferences()->markPageSeen('dashboard')->markPageSeen('admin', null, $at);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->find(HostPreferencesUser::class, 'u2');
        $this->assertInstanceOf(HostPreferencesUser::class, $reloaded);
        $this->assertTrue($reloaded->getUiPreferences()->isProductTourSeen());
        $this->assertSame('2026-10-13 10:00:00', $reloaded->getUiPreferences()->getProductTourSeenAt()?->format('Y-m-d H:i:s'));
        $this->assertTrue($reloaded->getUiPreferences()->isPushNotificationsEnabled());
    }

    private function entityManager(): EntityManagerInterface
    {
        $this->kernel = new TestKernel();
        $this->kernel->boot();

        $entityManager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
