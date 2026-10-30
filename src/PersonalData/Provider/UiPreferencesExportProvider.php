<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Provider;

use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\Model\UiPreferencesInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * Section `ui_preferences`: the nine `preferred_*` values (resolved, never null).
 */
final class UiPreferencesExportProvider implements PersonalDataExportProviderInterface
{
    /**
     * Returns the export section key.
     */
    public function getSectionKey(): string
    {
        return 'ui_preferences';
    }

    /**
     * Whether a UI preferences model can be resolved for the user.
     */
    public function supports(object $user): bool
    {
        return ModelResolver::uiPreferences($user) instanceof UiPreferencesInterface;
    }

    /**
     * Exports the resolved UI preference values of the user.
     *
     * @return array<string, string>
     */
    public function export(object $user): array
    {
        $preferences = ModelResolver::uiPreferences($user);
        if (!$preferences instanceof UiPreferencesInterface) {
            return [];
        }

        return [
            'preferred_locale'        => $preferences->getPreferredLocale(),
            'preferred_theme'         => $preferences->getPreferredTheme(),
            'preferred_content_width' => $preferences->getPreferredContentWidth(),
            'preferred_ui_density'    => $preferences->getPreferredUiDensity(),
            'preferred_motion'        => $preferences->getPreferredMotion(),
            'preferred_font_scale'    => $preferences->getPreferredFontScale(),
            'preferred_contrast'      => $preferences->getPreferredContrast(),
            'preferred_sidebar'       => $preferences->getPreferredSidebar(),
            'preferred_footer'        => $preferences->getPreferredFooter(),
        ];
    }
}
