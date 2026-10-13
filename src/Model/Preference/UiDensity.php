<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * UI density (`data-ui-density`).
 */
enum UiDensity: string
{
    use PreferenceChoiceTrait;

    case Comfortable = 'comfortable';
    case Compact     = 'compact';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Comfortable;
}
