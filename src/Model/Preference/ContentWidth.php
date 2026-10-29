<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Main content width: a centered max-width (`narrow`, `content`, `wide`, `wider`) or `full`. Hosts size each centered
 * width with CSS on `data-content-width`.
 */
enum ContentWidth: string
{
    use PreferenceChoiceTrait;

    case Narrow  = 'narrow';
    case Content = 'content';
    case Wide    = 'wide';
    case Wider   = 'wider';
    case Full    = 'full';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Content;
}
