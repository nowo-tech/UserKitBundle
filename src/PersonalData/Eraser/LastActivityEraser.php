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
    /**
     * Returns the stable key reported in the erasure result.
     */
    public function getKey(): string
    {
        return 'last_activity';
    }

    /**
     * Whether the user exposes `clearLastActivityAt()`.
     */
    public function supports(object $user): bool
    {
        return is_callable([$user, 'clearLastActivityAt']);
    }

    /**
     * Clears the last activity timestamp of the user.
     */
    public function erase(ErasureRequest $request): void
    {
        $clear = [$request->user, 'clearLastActivityAt'];
        if (is_callable($clear)) {
            $clear();
        }
    }
}
