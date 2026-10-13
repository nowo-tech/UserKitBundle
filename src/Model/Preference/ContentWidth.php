<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Main content width: centered max-width (`content`) or `full`.
 */
enum ContentWidth: string
{
    use PreferenceChoiceTrait;

    case Content = 'content';
    case Full    = 'full';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Content;
}
