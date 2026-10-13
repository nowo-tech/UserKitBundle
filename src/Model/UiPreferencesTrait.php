<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\Preference\ContentWidth;
use Nowo\UserKitBundle\Model\Preference\Contrast;
use Nowo\UserKitBundle\Model\Preference\FontScale;
use Nowo\UserKitBundle\Model\Preference\FooterMode;
use Nowo\UserKitBundle\Model\Preference\Motion;
use Nowo\UserKitBundle\Model\Preference\SidebarMode;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\Model\Preference\UiDensity;

use function strtolower;
use function trim;

/**
 * Generic UI preference columns (`preferred_*`), usable in an embeddable or directly on an entity.
 *
 * Column names are explicit, so they do not depend on the host naming strategy; embed with
 * `columnPrefix: false` to keep them unprefixed. All columns are nullable strings without a
 * database default: null means "use the default" ({@see Theme::DEFAULT} and friends).
 *
 * Setters accept the enum, a raw string (trimmed and lower-cased) or null; unknown values are
 * stored as null (never throw), so form / AJAX input can be passed as-is.
 */
trait UiPreferencesTrait
{
    /** Fallback returned by {@see getPreferredLocale()} when no locale is stored. */
    public const DEFAULT_LOCALE = 'en';

    #[ORM\Column(name: 'preferred_locale', type: Types::STRING, length: 8, nullable: true)]
    private ?string $preferredLocale = null;

    #[ORM\Column(name: 'preferred_theme', type: Types::STRING, length: 8, nullable: true)]
    private ?string $preferredTheme = 'light';

    #[ORM\Column(name: 'preferred_content_width', type: Types::STRING, length: 8, nullable: true)]
    private ?string $preferredContentWidth = 'content';

    #[ORM\Column(name: 'preferred_ui_density', type: Types::STRING, length: 16, nullable: true)]
    private ?string $preferredUiDensity = 'comfortable';

    #[ORM\Column(name: 'preferred_motion', type: Types::STRING, length: 16, nullable: true)]
    private ?string $preferredMotion = 'system';

    #[ORM\Column(name: 'preferred_font_scale', type: Types::STRING, length: 8, nullable: true)]
    private ?string $preferredFontScale = 'md';

    #[ORM\Column(name: 'preferred_contrast', type: Types::STRING, length: 8, nullable: true)]
    private ?string $preferredContrast = 'system';

    #[ORM\Column(name: 'preferred_sidebar', type: Types::STRING, length: 16, nullable: true)]
    private ?string $preferredSidebar = 'expanded';

    #[ORM\Column(name: 'preferred_footer', type: Types::STRING, length: 16, nullable: true)]
    private ?string $preferredFooter = 'scroll';

    public function getPreferredLocale(): string
    {
        return $this->preferredLocale ?? self::DEFAULT_LOCALE;
    }

    public function getPreferredLocaleRaw(): ?string
    {
        return $this->preferredLocale;
    }

    public function setPreferredLocale(?string $preferredLocale): static
    {
        $normalized            = $preferredLocale !== null ? strtolower(trim($preferredLocale)) : null;
        $this->preferredLocale = $normalized !== '' ? $normalized : null;

        return $this;
    }

    public function getPreferredTheme(): string
    {
        return (Theme::normalize($this->preferredTheme) ?? Theme::DEFAULT)->value;
    }

    public function getPreferredThemeRaw(): ?string
    {
        return $this->preferredTheme;
    }

    public function setPreferredTheme(Theme|string|null $preferredTheme): static
    {
        $this->preferredTheme = Theme::normalize($preferredTheme)?->value;

        return $this;
    }

    public function getPreferredContentWidth(): string
    {
        return (ContentWidth::normalize($this->preferredContentWidth) ?? ContentWidth::DEFAULT)->value;
    }

    public function getPreferredContentWidthRaw(): ?string
    {
        return $this->preferredContentWidth;
    }

    public function setPreferredContentWidth(ContentWidth|string|null $preferredContentWidth): static
    {
        $this->preferredContentWidth = ContentWidth::normalize($preferredContentWidth)?->value;

        return $this;
    }

    public function getPreferredUiDensity(): string
    {
        return (UiDensity::normalize($this->preferredUiDensity) ?? UiDensity::DEFAULT)->value;
    }

    public function getPreferredUiDensityRaw(): ?string
    {
        return $this->preferredUiDensity;
    }

    public function setPreferredUiDensity(UiDensity|string|null $preferredUiDensity): static
    {
        $this->preferredUiDensity = UiDensity::normalize($preferredUiDensity)?->value;

        return $this;
    }

    public function getPreferredMotion(): string
    {
        return (Motion::normalize($this->preferredMotion) ?? Motion::DEFAULT)->value;
    }

    public function getPreferredMotionRaw(): ?string
    {
        return $this->preferredMotion;
    }

    public function setPreferredMotion(Motion|string|null $preferredMotion): static
    {
        $this->preferredMotion = Motion::normalize($preferredMotion)?->value;

        return $this;
    }

    public function getPreferredFontScale(): string
    {
        return (FontScale::normalize($this->preferredFontScale) ?? FontScale::DEFAULT)->value;
    }

    public function getPreferredFontScaleRaw(): ?string
    {
        return $this->preferredFontScale;
    }

    public function setPreferredFontScale(FontScale|string|null $preferredFontScale): static
    {
        $this->preferredFontScale = FontScale::normalize($preferredFontScale)?->value;

        return $this;
    }

    public function getPreferredContrast(): string
    {
        return (Contrast::normalize($this->preferredContrast) ?? Contrast::DEFAULT)->value;
    }

    public function getPreferredContrastRaw(): ?string
    {
        return $this->preferredContrast;
    }

    public function setPreferredContrast(Contrast|string|null $preferredContrast): static
    {
        $this->preferredContrast = Contrast::normalize($preferredContrast)?->value;

        return $this;
    }

    public function getPreferredSidebar(): string
    {
        return (SidebarMode::normalize($this->preferredSidebar) ?? SidebarMode::DEFAULT)->value;
    }

    public function getPreferredSidebarRaw(): ?string
    {
        return $this->preferredSidebar;
    }

    public function setPreferredSidebar(SidebarMode|string|null $preferredSidebar): static
    {
        $this->preferredSidebar = SidebarMode::normalize($preferredSidebar)?->value;

        return $this;
    }

    public function getPreferredFooter(): string
    {
        return (FooterMode::normalize($this->preferredFooter) ?? FooterMode::DEFAULT)->value;
    }

    public function getPreferredFooterRaw(): ?string
    {
        return $this->preferredFooter;
    }

    public function setPreferredFooter(FooterMode|string|null $preferredFooter): static
    {
        $this->preferredFooter = FooterMode::normalize($preferredFooter)?->value;

        return $this;
    }

    /**
     * Fills every null column with its default (and the locale with `$defaultLocale`), so new
     * accounts persist concrete values. Typically called from a Doctrine `prePersist` listener.
     */
    public function applyMissingUiPreferences(string $defaultLocale = self::DEFAULT_LOCALE): static
    {
        $locale = strtolower(trim($defaultLocale));

        $this->preferredLocale ??= $locale !== '' ? $locale : self::DEFAULT_LOCALE;
        $this->preferredTheme ??= Theme::DEFAULT->value;
        $this->preferredContentWidth ??= ContentWidth::DEFAULT->value;
        $this->preferredUiDensity ??= UiDensity::DEFAULT->value;
        $this->preferredMotion ??= Motion::DEFAULT->value;
        $this->preferredFontScale ??= FontScale::DEFAULT->value;
        $this->preferredContrast ??= Contrast::DEFAULT->value;
        $this->preferredSidebar ??= SidebarMode::DEFAULT->value;
        $this->preferredFooter ??= FooterMode::DEFAULT->value;

        return $this;
    }

    /**
     * Clears every UI preference column (GDPR erasure / anonymisation).
     */
    public function resetUiPreferences(): static
    {
        $this->preferredLocale       = null;
        $this->preferredTheme        = null;
        $this->preferredContentWidth = null;
        $this->preferredUiDensity    = null;
        $this->preferredMotion       = null;
        $this->preferredFontScale    = null;
        $this->preferredContrast     = null;
        $this->preferredSidebar      = null;
        $this->preferredFooter       = null;

        return $this;
    }
}
