<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\ProductTourProgress;
use Nowo\UserKitBundle\Model\UiPreferences;

/**
 * Embeds the ready-made kit embeddables (columnPrefix: false).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_kit_prefs_user')]
class PreferencesUser
{
    #[ORM\Embedded(class: UiPreferences::class, columnPrefix: false)]
    private UiPreferences $uiPreferences;

    #[ORM\Embedded(class: ProductTourProgress::class, columnPrefix: false)]
    private ProductTourProgress $productTourProgress;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $id,
    ) {
        $this->uiPreferences       = new UiPreferences();
        $this->productTourProgress = new ProductTourProgress();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUiPreferences(): UiPreferences
    {
        return $this->uiPreferences;
    }

    public function getProductTourProgress(): ProductTourProgress
    {
        return $this->productTourProgress;
    }
}
