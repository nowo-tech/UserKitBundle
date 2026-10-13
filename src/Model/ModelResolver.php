<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use function is_callable;

/**
 * Finds a UserKit model (e.g. {@see UiPreferencesInterface}) on a user: either the user itself
 * implements it, or one of the given no-argument getters returns an instance of it.
 */
final class ModelResolver
{
    /** Getters tried for {@see UiPreferencesInterface}. */
    public const UI_PREFERENCES_GETTERS = ['getUiPreferences'];

    /** Getters tried for {@see ProductTourProgressInterface}. */
    public const PRODUCT_TOUR_GETTERS = ['getProductTourProgress', 'getProductTour', 'getUiPreferences'];

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @param list<string> $getters
     *
     * @return T|null
     */
    public static function find(?object $subject, string $type, array $getters): ?object
    {
        if ($subject === null) {
            return null;
        }

        if ($subject instanceof $type) {
            return $subject;
        }

        foreach ($getters as $getter) {
            $callable = [$subject, $getter];
            if (!is_callable($callable)) {
                continue;
            }

            $candidate = $callable();
            if ($candidate instanceof $type) {
                return $candidate;
            }
        }

        return null;
    }

    public static function uiPreferences(?object $subject): ?UiPreferencesInterface
    {
        return self::find($subject, UiPreferencesInterface::class, self::UI_PREFERENCES_GETTERS);
    }

    public static function productTour(?object $subject): ?ProductTourProgressInterface
    {
        return self::find($subject, ProductTourProgressInterface::class, self::PRODUCT_TOUR_GETTERS);
    }
}
