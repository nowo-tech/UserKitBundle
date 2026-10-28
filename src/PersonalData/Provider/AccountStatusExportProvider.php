<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Provider;

use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Section `account_status`: `{enabled: bool}` (AccountStatusInterface or the profile `account_status.field`).
 */
final readonly class AccountStatusExportProvider implements PersonalDataExportProviderInterface
{
    public function __construct(
        private ProfileRegistry $registry,
        private PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function getSectionKey(): string
    {
        return 'account_status';
    }

    public function supports(object $user): bool
    {
        return $user instanceof AccountStatusInterface || $this->field($user) !== null;
    }

    /**
     * @return array{enabled: bool}
     */
    public function export(object $user): array
    {
        if ($user instanceof AccountStatusInterface) {
            return ['enabled' => $user->isEnabled()];
        }

        $field = $this->field($user);

        return ['enabled' => $field !== null && (bool) $this->propertyAccessor->getValue($user, $field)];
    }

    private function field(object $user): ?string
    {
        $profile = $this->registry->resolveForObject($user);
        if (!$profile instanceof ProfileSettings || !$profile->accountStatusEnabled || !$this->propertyAccessor->isReadable($user, $profile->enabledField)) {
            return null;
        }

        return $profile->enabledField;
    }
}
