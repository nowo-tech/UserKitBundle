<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\Model;

use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\Model\ProductTourProgress;
use Nowo\UserKitBundle\Model\UiPreferences;
use Nowo\UserKitBundle\Tests\Integration\Fixtures\Entity\PreferencesUser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModelResolverTest extends TestCase
{
    public function testResolution(): void
    {
        $preferences = new UiPreferences();
        $user        = new PreferencesUser('u');

        $this->assertNull(ModelResolver::uiPreferences(null));
        $this->assertNull(ModelResolver::uiPreferences(new stdClass()));
        $this->assertSame($preferences, ModelResolver::uiPreferences($preferences));
        $this->assertSame($user->getUiPreferences(), ModelResolver::uiPreferences($user));
        $this->assertSame($user->getProductTourProgress(), ModelResolver::productTour($user));
        $this->assertInstanceOf(ProductTourProgress::class, ModelResolver::productTour(new ProductTourProgress()));
        $this->assertNull(ModelResolver::productTour(new class {
            public function getUiPreferences(): stdClass
            {
                return new stdClass();
            }
        }));
    }
}
