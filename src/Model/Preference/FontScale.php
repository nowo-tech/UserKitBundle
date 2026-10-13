<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Root font scale (`data-font-scale`).
 */
enum FontScale: string
{
    use PreferenceChoiceTrait;

    case Sm = 'sm';
    case Md = 'md';
    case Lg = 'lg';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Md;
}
