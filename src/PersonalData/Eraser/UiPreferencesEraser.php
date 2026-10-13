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
    public function getKey(): string
    {
        return 'ui_preferences';
    }

    public function supports(object $user): bool
    {
        return is_callable([ModelResolver::uiPreferences($user), 'resetUiPreferences']);
    }

    public function erase(ErasureRequest $request): void
    {
        $reset = [ModelResolver::uiPreferences($request->user), 'resetUiPreferences'];
        if (is_callable($reset)) {
            $reset();
        }
    }
}
