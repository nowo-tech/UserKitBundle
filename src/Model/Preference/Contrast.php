<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Contrast (`data-contrast`): follow the device or force more contrast.
 */
enum Contrast: string
{
    use PreferenceChoiceTrait;

    case System = 'system';
    case More   = 'more';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::System;
}
