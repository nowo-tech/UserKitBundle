<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model\Preference;

use function is_string;
use function strtolower;
use function trim;

/**
 * Shared helpers for the string-backed UI preference enums.
 */
trait PreferenceChoiceTrait
{
    /**
     * Lower-cases and trims a raw value; returns null for empty or unknown values.
     */
    public static function normalize(self|string|null $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? null : self::tryFrom($normalized);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
