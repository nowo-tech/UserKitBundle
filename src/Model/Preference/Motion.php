<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Motion preference (`data-motion`): follow the OS, reduce or full.
 */
enum Motion: string
{
    use PreferenceChoiceTrait;

    case System = 'system';
    case Reduce = 'reduce';
    case Full   = 'full';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::System;
}
