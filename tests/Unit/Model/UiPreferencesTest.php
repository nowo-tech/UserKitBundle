<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Model;

use Nowo\UserKitBundle\Model\Preference\ContentWidth;
use Nowo\UserKitBundle\Model\Preference\Contrast;
use Nowo\UserKitBundle\Model\Preference\FontScale;
use Nowo\UserKitBundle\Model\Preference\FooterMode;
use Nowo\UserKitBundle\Model\Preference\Motion;
use Nowo\UserKitBundle\Model\Preference\SidebarMode;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\Model\Preference\UiDensity;
use Nowo\UserKitBundle\Model\UiPreferences;
use PHPUnit\Framework\TestCase;

final class UiPreferencesTest extends TestCase
{
    public function testDefaultsMatchTheHostDefaults(): void
    {
        $preferences = new UiPreferences();

        $this->assertSame('en', $preferences->getPreferredLocale());
        $this->assertNull($preferences->getPreferredLocaleRaw());
        $this->assertSame('light', $preferences->getPreferredThemeRaw());
        $this->assertSame('content', $preferences->getPreferredContentWidthRaw());
        $this->assertSame('comfortable', $preferences->getPreferredUiDensityRaw());
        $this->assertSame('system', $preferences->getPreferredMotionRaw());
        $this->assertSame('md', $preferences->getPreferredFontScaleRaw());
        $this->assertSame('system', $preferences->getPreferredContrastRaw());
        $this->assertSame('expanded', $preferences->getPreferredSidebarRaw());
        $this->assertSame('scroll', $preferences->getPreferredFooterRaw());
    }

    public function testSettersAcceptEnumsAndNormalizeStrings(): void
    {
        $preferences = (new UiPreferences())
            ->setPreferredLocale('  PT ')
            ->setPreferredTheme(Theme::Dark)
            ->setPreferredContentWidth(' FULL ')
            ->setPreferredUiDensity(UiDensity::Compact)
            ->setPreferredMotion('reduce')
            ->setPreferredFontScale(FontScale::Lg)
            ->setPreferredContrast('More')
            ->setPreferredSidebar(SidebarMode::Rail)
            ->setPreferredFooter('fixed');

        $this->assertSame('pt', $preferences->getPreferredLocale());
        $this->assertSame('dark', $preferences->getPreferredTheme());
        $this->assertSame('full', $preferences->getPreferredContentWidth());
        $this->assertSame('compact', $preferences->getPreferredUiDensity());
        $this->assertSame('reduce', $preferences->getPreferredMotion());
        $this->assertSame('lg', $preferences->getPreferredFontScale());
        $this->assertSame('more', $preferences->getPreferredContrast());
        $this->assertSame('rail', $preferences->getPreferredSidebar());
        $this->assertSame('fixed', $preferences->getPreferredFooter());
    }

    public function testUnknownValuesAreStoredAsNullAndGettersFallBack(): void
    {
        $preferences = (new UiPreferences())
            ->setPreferredLocale('   ')
            ->setPreferredTheme('sepia')
            ->setPreferredContentWidth('wide')
            ->setPreferredUiDensity('')
            ->setPreferredMotion(null)
            ->setPreferredFontScale('xl')
            ->setPreferredContrast('less')
            ->setPreferredSidebar('floating')
            ->setPreferredFooter('sticky');

        $this->assertNull($preferences->getPreferredLocaleRaw());
        $this->assertNull($preferences->getPreferredThemeRaw());
        $this->assertNull($preferences->getPreferredContentWidthRaw());
        $this->assertNull($preferences->getPreferredUiDensityRaw());
        $this->assertNull($preferences->getPreferredMotionRaw());
        $this->assertNull($preferences->getPreferredFontScaleRaw());
        $this->assertNull($preferences->getPreferredContrastRaw());
        $this->assertNull($preferences->getPreferredSidebarRaw());
        $this->assertNull($preferences->getPreferredFooterRaw());

        $this->assertSame(Theme::DEFAULT->value, $preferences->getPreferredTheme());
        $this->assertSame(ContentWidth::DEFAULT->value, $preferences->getPreferredContentWidth());
        $this->assertSame(UiDensity::DEFAULT->value, $preferences->getPreferredUiDensity());
        $this->assertSame(Motion::DEFAULT->value, $preferences->getPreferredMotion());
        $this->assertSame(FontScale::DEFAULT->value, $preferences->getPreferredFontScale());
        $this->assertSame(Contrast::DEFAULT->value, $preferences->getPreferredContrast());
        $this->assertSame(SidebarMode::DEFAULT->value, $preferences->getPreferredSidebar());
        $this->assertSame(FooterMode::DEFAULT->value, $preferences->getPreferredFooter());
    }

    public function testResetAndApplyMissing(): void
    {
        $preferences = (new UiPreferences())->setPreferredTheme('dark')->resetUiPreferences();

        $this->assertNull($preferences->getPreferredThemeRaw());
        $this->assertNull($preferences->getPreferredFooterRaw());

        $preferences->applyMissingUiPreferences(' FR ');
        $this->assertSame('fr', $preferences->getPreferredLocaleRaw());
        $this->assertSame('light', $preferences->getPreferredThemeRaw());
        $this->assertSame('content', $preferences->getPreferredContentWidthRaw());
        $this->assertSame('comfortable', $preferences->getPreferredUiDensityRaw());
        $this->assertSame('system', $preferences->getPreferredMotionRaw());
        $this->assertSame('md', $preferences->getPreferredFontScaleRaw());
        $this->assertSame('system', $preferences->getPreferredContrastRaw());
        $this->assertSame('expanded', $preferences->getPreferredSidebarRaw());
        $this->assertSame('scroll', $preferences->getPreferredFooterRaw());

        $preferences->resetUiPreferences()->applyMissingUiPreferences(' ');
        $this->assertSame('en', $preferences->getPreferredLocaleRaw());

        $preferences->setPreferredTheme('dark')->applyMissingUiPreferences();
        $this->assertSame('dark', $preferences->getPreferredThemeRaw());
    }

    public function testEnumHelpers(): void
    {
        $this->assertSame(['system', 'reduce', 'full'], Motion::values());
        $this->assertSame(Theme::Dark, Theme::normalize(Theme::Dark));
        $this->assertSame(Theme::Dark, Theme::normalize(' DARK'));
        $this->assertNull(Theme::normalize(null));
        $this->assertNull(Theme::normalize(''));
        $this->assertNull(Theme::normalize('blue'));
    }
}
