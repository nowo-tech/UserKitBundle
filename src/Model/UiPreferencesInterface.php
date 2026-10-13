<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

/**
 * Generic UI / display preferences of a user.
 *
 * Getters always return a valid choice (the enum `DEFAULT` when nothing is stored); the
 * `*Raw()` getters of {@see UiPreferencesTrait} expose the stored (nullable) value.
 */
interface UiPreferencesInterface
{
    public function getPreferredLocale(): string;

    public function getPreferredTheme(): string;

    public function getPreferredContentWidth(): string;

    public function getPreferredUiDensity(): string;

    public function getPreferredMotion(): string;

    public function getPreferredFontScale(): string;

    public function getPreferredContrast(): string;

    public function getPreferredSidebar(): string;

    public function getPreferredFooter(): string;
}
