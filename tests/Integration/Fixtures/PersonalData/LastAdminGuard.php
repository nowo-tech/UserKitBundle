<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\PersonalData;

use Nowo\UserKitBundle\PersonalData\ErasureGuardInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\ErasureVeto;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\GdprUser;

final class LastAdminGuard implements ErasureGuardInterface
{
    public function check(ErasureRequest $request): ?ErasureVeto
    {
        if ($request->user instanceof GdprUser && $request->user->getId() === 'admin') {
            return new ErasureVeto('last_admin', 'The last administrator cannot be erased.');
        }

        return null;
    }
}
