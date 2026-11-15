<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Load\Support;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\Tests\Integration\Sessions\Fixtures\Member;

/**
 * A host eraser that changes the member row (an UPDATE in the erasure's flush, inside its transaction).
 */
final class AnonymisingEraser implements PersonalDataEraserInterface
{
    public function getKey(): string
    {
        return 'member';
    }

    public function supports(object $user): bool
    {
        return $user instanceof Member;
    }

    public function erase(ErasureRequest $request): void
    {
        $user = $request->user;
        if ($user instanceof Member) {
            $user->setPassword('erased');
        }
    }
}
