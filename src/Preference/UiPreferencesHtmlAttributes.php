<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Preference;

use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\Model\Preference\FooterMode;
use Nowo\UserKitBundle\Model\UiPreferencesInterface;

use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Builds the `<html>` `data-*` attributes read by the pre-paint theme boot script.
 *
 * Attribute names follow the existing host contract (Beacon `theme-boot.ts`):
 * `data-theme` plus the `data-user-*` inputs (theme, density, motion, font-scale, contrast,
 * sidebar, footer) and `data-footer-fixed="1"`. With `$resolved`, the server-side resolved
 * attributes (`data-ui-density`, `data-motion`, `data-font-scale`, `data-contrast`,
 * `data-content-width`) are printed as well, so pages render correctly without the boot script.
 */
final class UiPreferencesHtmlAttributes
{
    /**
     * @param object|null $subject a {@see UiPreferencesInterface}, or a user exposing `getUiPreferences()`
     *
     * @return array<string, string> attribute name => value (empty for guests / users without preferences)
     */
    public function build(?object $subject, bool $resolved = false): array
    {
        $preferences = ModelResolver::uiPreferences($subject);
        if (!$preferences instanceof UiPreferencesInterface) {
            return [];
        }

        $theme      = $preferences->getPreferredTheme();
        $attributes = [];
        if ($preferences->getPreferredFooter() === FooterMode::Fixed->value) {
            $attributes['data-footer-fixed'] = '1';
        }

        $attributes += [
            'data-theme'           => $theme,
            'data-user-theme'      => $theme,
            'data-user-density'    => $preferences->getPreferredUiDensity(),
            'data-user-motion'     => $preferences->getPreferredMotion(),
            'data-user-font-scale' => $preferences->getPreferredFontScale(),
            'data-user-contrast'   => $preferences->getPreferredContrast(),
            'data-user-sidebar'    => $preferences->getPreferredSidebar(),
            'data-user-footer'     => $preferences->getPreferredFooter(),
        ];

        if ($resolved) {
            $attributes += [
                'data-ui-density'    => $preferences->getPreferredUiDensity(),
                'data-motion'        => $preferences->getPreferredMotion(),
                'data-font-scale'    => $preferences->getPreferredFontScale(),
                'data-contrast'      => $preferences->getPreferredContrast(),
                'data-content-width' => $preferences->getPreferredContentWidth(),
            ];
        }

        return $attributes;
    }

    /**
     * Renders {@see build()} as an escaped HTML attribute string (`data-theme="dark" …`).
     */
    public function render(?object $subject, bool $resolved = false): string
    {
        $parts = [];
        foreach ($this->build($subject, $resolved) as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return implode(' ', $parts);
    }
}
