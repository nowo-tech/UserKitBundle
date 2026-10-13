<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ready-made embeddable for the generic UI preferences.
 *
 * ```php
 * #[ORM\Embedded(class: UiPreferences::class, columnPrefix: false)]
 * private UiPreferences $uiPreferences;
 * ```
 *
 * Hosts with extra preference columns can instead `use UiPreferencesTrait` in their own
 * embeddable (same column names, no migration).
 */
#[ORM\Embeddable]
class UiPreferences implements UiPreferencesInterface
{
    use UiPreferencesTrait;
}
