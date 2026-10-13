<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ready-made embeddable for product-tour seen tracking.
 *
 * ```php
 * #[ORM\Embedded(class: ProductTourProgress::class, columnPrefix: false)]
 * private ProductTourProgress $productTour;
 * ```
 *
 * Pass the tour catalog as `$knownPages`, or `use ProductTourProgressTrait` in a host
 * embeddable that overrides `productTourPages()`.
 */
#[ORM\Embeddable]
class ProductTourProgress implements ProductTourProgressInterface
{
    use ProductTourProgressTrait;
}
