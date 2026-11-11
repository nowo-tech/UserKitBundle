<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\DddHost\Domain;

use Nowo\UserKitBundle\Model\ProductTourProgress;
use Nowo\UserKitBundle\Model\UiPreferences;

/**
 * DDD host aggregate: plain PHP, no Doctrine attribute, no bundle base class or trait. Mapped by XML
 * (`config/doctrine/Account.orm.xml`); embeds the kit embeddables mapped by the bundle's XML.
 */
class Account
{
    private readonly UiPreferences $uiPreferences;

    private readonly ProductTourProgress $productTour;

    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private string $password,
    ) {
        $this->uiPreferences = new UiPreferences();
        $this->productTour   = new ProductTourProgress();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function changePassword(string $password): void
    {
        $this->password = $password;
    }

    public function uiPreferences(): UiPreferences
    {
        return $this->uiPreferences;
    }

    public function productTour(): ProductTourProgress
    {
        return $this->productTour;
    }
}
