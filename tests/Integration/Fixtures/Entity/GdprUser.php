<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\Model\AccountStatusInterface;
use Nowo\UserKitBundle\Model\EnabledUserTrait;
use Nowo\UserKitBundle\Model\LastActivityInterface;
use Nowo\UserKitBundle\Model\LastActivityTrait;
use Nowo\UserKitBundle\Model\ProductTourProgress;
use Nowo\UserKitBundle\Model\UiPreferences;

#[ORM\Entity]
#[ORM\Table(name: 'user_kit_gdpr_user')]
class GdprUser implements AccountStatusInterface, LastActivityInterface
{
    use EnabledUserTrait;
    use LastActivityTrait;

    #[ORM\Embedded(class: UiPreferences::class, columnPrefix: false)]
    private UiPreferences $uiPreferences;

    #[ORM\Embedded(class: ProductTourProgress::class, columnPrefix: false)]
    private ProductTourProgress $productTourProgress;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $id,
        #[ORM\Column(type: 'string', length: 180)]
        private string $email,
    ) {
        $this->uiPreferences       = new UiPreferences();
        $this->productTourProgress = new ProductTourProgress();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
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
