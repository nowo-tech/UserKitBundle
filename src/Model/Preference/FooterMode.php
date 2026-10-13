<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

/**
 * Footer: scrolls with the document or stays fixed to the viewport.
 */
enum FooterMode: string
{
    use PreferenceChoiceTrait;

    case Scroll = 'scroll';
    case Fixed  = 'fixed';

    /** Value used when nothing (or an unknown value) is stored. */
    public const DEFAULT = self::Scroll;
}
