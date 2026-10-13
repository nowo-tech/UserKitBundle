<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\ProductTourProgressInterface;
use Nowo\UserKitBundle\Model\ProductTourProgressTrait;
use Nowo\UserKitBundle\Model\UiPreferencesInterface;
use Nowo\UserKitBundle\Model\UiPreferencesTrait;

/**
 * Host embeddable mixing the kit traits with a host-only column (Beacon-style adoption).
 */
#[ORM\Embeddable]
class HostUiPreferences implements UiPreferencesInterface, ProductTourProgressInterface
{
    use ProductTourProgressTrait;
    use UiPreferencesTrait;

    #[ORM\Column(name: 'push_notifications_enabled', options: ['default' => true])]
    private bool $pushNotificationsEnabled = true;

    public function isPushNotificationsEnabled(): bool
    {
        return $this->pushNotificationsEnabled;
    }

    /**
     * @return list<string>
     */
    protected function productTourPages(): array
    {
        return ['dashboard', 'admin'];
    }
}
