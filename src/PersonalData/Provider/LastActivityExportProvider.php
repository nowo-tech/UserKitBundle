<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Provider;

use DateTimeInterface;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\UserKitBundle\Profile\ProfileRegistry;
use Nowo\UserKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Section `last_activity`: `{last_activity_at: ?string}` (LastActivityInterface or the profile `last_activity.field`).
 */
final readonly class LastActivityExportProvider implements PersonalDataExportProviderInterface
{
    /**
     * Receives the profile registry and the property accessor.
     */
    public function __construct(
        private ProfileRegistry $registry,
        private PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * Returns the export section key.
     */
    public function getSectionKey(): string
    {
        return 'last_activity';
    }

    /**
     * Whether the user implements LastActivityInterface or has a readable profile last-activity field.
     */
    public function supports(object $user): bool
    {
        return $user instanceof LastActivityInterface || $this->field($user) !== null;
    }

    /**
     * Exports the last activity timestamp of the user.
     *
     * @return array{last_activity_at: ?DateTimeInterface}
     */
    public function export(object $user): array
    {
        if ($user instanceof LastActivityInterface) {
            return ['last_activity_at' => $user->getLastActivityAt()];
        }

        $field = $this->field($user);
        $value = $field !== null ? $this->propertyAccessor->getValue($user, $field) : null;

        return ['last_activity_at' => $value instanceof DateTimeInterface ? $value : null];
    }

    private function field(object $user): ?string
    {
        $profile = $this->registry->resolveForObject($user);
        if (!$profile instanceof ProfileSettings || !$profile->lastActivityEnabled || !$this->propertyAccessor->isReadable($user, $profile->lastActivityField)) {
            return null;
        }

        return $profile->lastActivityField;
    }
}
