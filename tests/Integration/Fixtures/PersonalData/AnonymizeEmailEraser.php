<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\PersonalData;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\GdprUser;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Host eraser; context `fail: true` throws after changing the entity (rollback test). */
#[AsTaggedItem(priority: 10)]
final class AnonymizeEmailEraser implements PersonalDataEraserInterface
{
    public function getKey(): string
    {
        return 'account';
    }

    public function supports(object $user): bool
    {
        return $user instanceof GdprUser;
    }

    public function erase(ErasureRequest $request): void
    {
        if (!$request->user instanceof GdprUser) {
            return;
        }

        $request->user->setEmail('anonymized-' . $request->user->getId() . '@invalid.local');
        $request->user->setEnabled(false);

        if (true === ($request->context['fail'] ?? false)) {
            throw new RuntimeException('boom');
        }
    }
}
