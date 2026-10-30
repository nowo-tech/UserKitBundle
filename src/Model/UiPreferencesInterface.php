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
    /**
     * Returns the preferred locale, falling back to the default when nothing is stored.
     */
    public function getPreferredLocale(): string;

    /**
     * Returns the preferred theme, falling back to the default when nothing is stored.
     */
    public function getPreferredTheme(): string;

    /**
     * Returns the preferred content width, falling back to the default when nothing is stored.
     */
    public function getPreferredContentWidth(): string;

    /**
     * Returns the preferred UI density, falling back to the default when nothing is stored.
     */
    public function getPreferredUiDensity(): string;

    /**
     * Returns the preferred motion preference, falling back to the default when nothing is stored.
     */
    public function getPreferredMotion(): string;

    /**
     * Returns the preferred font scale, falling back to the default when nothing is stored.
     */
    public function getPreferredFontScale(): string;

    /**
     * Returns the preferred contrast mode, falling back to the default when nothing is stored.
     */
    public function getPreferredContrast(): string;

    /**
     * Returns the preferred sidebar mode, falling back to the default when nothing is stored.
     */
    public function getPreferredSidebar(): string;

    /**
     * Returns the preferred footer mode, falling back to the default when nothing is stored.
     */
    public function getPreferredFooter(): string;
}
