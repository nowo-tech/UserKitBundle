<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Eraser;

use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

use function is_callable;

/**
 * Clears the UI preference columns (models using `UiPreferencesTrait`: `resetUiPreferences()`).
 */
final class UiPreferencesEraser implements PersonalDataEraserInterface
{
    /**
     * Returns the stable key reported in the erasure result.
     */
    public function getKey(): string
    {
        return 'ui_preferences';
    }

    /**
     * Whether the resolved UI preferences model exposes `resetUiPreferences()`.
     */
    public function supports(object $user): bool
    {
        return is_callable([ModelResolver::uiPreferences($user), 'resetUiPreferences']);
    }

    /**
     * Clears every UI preference column of the user.
     */
    public function erase(ErasureRequest $request): void
    {
        $reset = [ModelResolver::uiPreferences($request->user), 'resetUiPreferences'];
        if (is_callable($reset)) {
            $reset();
        }
    }
}
