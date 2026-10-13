<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Eraser;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

use function is_callable;

/**
 * Clears `lastActivityAt` on users exposing `clearLastActivityAt()` (provided by `LastActivityTrait`).
 */
final class LastActivityEraser implements PersonalDataEraserInterface
{
    public function getKey(): string
    {
        return 'last_activity';
    }

    public function supports(object $user): bool
    {
        return is_callable([$user, 'clearLastActivityAt']);
    }

    public function erase(ErasureRequest $request): void
    {
        $clear = [$request->user, 'clearLastActivityAt'];
        if (is_callable($clear)) {
            $clear();
        }
    }
}
