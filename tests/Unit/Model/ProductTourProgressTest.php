<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\Model\ProductTourProgress;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\HostUiPreferences;
use PHPUnit\Framework\TestCase;

final class ProductTourProgressTest extends TestCase
{
    private const PAGES = ['dashboard', 'issues', 'admin'];

    public function testFreshProgress(): void
    {
        $progress = new ProductTourProgress();

        $this->assertNull($progress->getProductTourSeenAt());
        $this->assertFalse($progress->isProductTourSeen());
        $this->assertSame([], $progress->getProductTourSeenPages());
        $this->assertFalse($progress->hasSeenPage('dashboard'));
        $this->assertSame(self::PAGES, $progress->getEnabledPages(self::PAGES));
    }

    public function testMarkPageSeenWithoutCatalogAcceptsAnyId(): void
    {
        $progress = (new ProductTourProgress())->markPageSeen('anything')->markPageSeen('anything')->markPageSeen('');

        $this->assertSame(['anything'], $progress->getProductTourSeenPages());
        $this->assertTrue($progress->hasSeenPage('anything'));
        $this->assertFalse($progress->isProductTourSeen());
    }

    public function testMarkPageSeenWithCatalog(): void
    {
        $at       = new DateTimeImmutable('2026-01-01');
        $progress = new ProductTourProgress();

        $progress->markPageSeen('unknown', self::PAGES);
        $this->assertSame([], $progress->getProductTourSeenPages(self::PAGES));

        $progress->markPageSeen('dashboard', self::PAGES)->markPageSeen('issues', self::PAGES);
        $this->assertFalse($progress->isProductTourSeen());
        $this->assertSame(['admin'], $progress->getEnabledPages(self::PAGES));

        $progress->markPageSeen('admin', self::PAGES, $at);
        $this->assertSame($at, $progress->getProductTourSeenAt());
        $this->assertTrue($progress->hasSeenPage('nothing-else'));
        $this->assertSame([], $progress->getEnabledPages(self::PAGES));

        $progress->markPageSeen('admin', self::PAGES);
        $this->assertSame($at, $progress->getProductTourSeenAt());
    }

    public function testEnumPages(): void
    {
        $progress = (new ProductTourProgress())->markPageSeen(Theme::Dark, Theme::cases());

        $this->assertTrue($progress->hasSeenPage(Theme::Dark, Theme::cases()));
        $this->assertSame(['light'], $progress->getEnabledPages(Theme::cases()));
    }

    public function testMarkAndClearAll(): void
    {
        $progress = (new ProductTourProgress())->markProductTourSeen();
        $this->assertTrue($progress->isProductTourSeen());
        $this->assertSame([], $progress->getProductTourSeenPages());

        $at = new DateTimeImmutable('2026-02-02');
        $progress->markProductTourSeen($at, self::PAGES);
        $this->assertSame($at, $progress->getProductTourSeenAt());
        $this->assertSame(self::PAGES, $progress->getProductTourSeenPages());

        $progress->clearProductTourSeen();
        $this->assertNull($progress->getProductTourSeenAt());
        $this->assertSame([], $progress->getProductTourSeenPages());
    }

    public function testSyncEnabledPages(): void
    {
        $at       = new DateTimeImmutable('2026-03-03');
        $progress = new ProductTourProgress();

        $progress->syncEnabledPages(['dashboard', 42, 'bogus'], self::PAGES);
        $this->assertSame(['issues', 'admin'], $progress->getProductTourSeenPages(self::PAGES));
        $this->assertFalse($progress->isProductTourSeen());

        $progress->syncEnabledPages([], self::PAGES, $at);
        $this->assertSame($at, $progress->getProductTourSeenAt());

        $progress->syncEnabledPages([], self::PAGES);
        $this->assertTrue($progress->isProductTourSeen());

        $progress->syncEnabledPages(self::PAGES, self::PAGES);
        $this->assertNull($progress->getProductTourSeenAt());
        $this->assertSame([], $progress->getProductTourSeenPages());
    }

    public function testCatalogRequiredForEnabledPages(): void
    {
        $this->expectException(LogicException::class);

        (new ProductTourProgress())->syncEnabledPages([]);
    }

    public function testCatalogFromOverriddenHook(): void
    {
        $preferences = new HostUiPreferences();
        $preferences->markPageSeen('unknown')->markPageSeen('dashboard');

        $this->assertSame(['admin'], $preferences->getEnabledPages());
        $this->assertSame(['dashboard'], $preferences->getProductTourSeenPages());
    }
}
