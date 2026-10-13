<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Colour theme (`data-theme`).
 */
enum Theme: string
{
    use PreferenceChoiceTrait;

    case Light = 'light';
    case Dark  = 'dark';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Light;
}
