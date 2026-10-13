<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\PersonalData;

use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\GdprUser;

/** Host section (default priority 0: before the built-in sections). */
final class AccountSectionProvider implements PersonalDataExportProviderInterface
{
    public function getSectionKey(): string
    {
        return 'account';
    }

    public function supports(object $user): bool
    {
        return $user instanceof GdprUser;
    }

    /**
     * @return array{email: string}
     */
    public function export(object $user): array
    {
        return ['email' => $user instanceof GdprUser ? $user->getEmail() : ''];
    }
}
