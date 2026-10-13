<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Desktop sidebar default: expanded, icon rail or collapsed (hidden).
 */
enum SidebarMode: string
{
    use PreferenceChoiceTrait;

    case Expanded  = 'expanded';
    case Rail      = 'rail';
    case Collapsed = 'collapsed';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Expanded;
}
