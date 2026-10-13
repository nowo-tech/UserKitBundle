<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_kit_host_prefs_user')]
class HostPreferencesUser
{
    #[ORM\Embedded(class: HostUiPreferences::class, columnPrefix: false)]
    private HostUiPreferences $uiPreferences;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $id,
    ) {
        $this->uiPreferences = new HostUiPreferences();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUiPreferences(): HostUiPreferences
    {
        return $this->uiPreferences;
    }
}
