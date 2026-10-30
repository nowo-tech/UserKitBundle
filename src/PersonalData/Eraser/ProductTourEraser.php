<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Eraser;

use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\Model\ProductTourProgressInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * Clears product-tour progress (`clearProductTourSeen()`).
 */
final class ProductTourEraser implements PersonalDataEraserInterface
{
    /**
     * Returns the stable key reported in the erasure result.
     */
    public function getKey(): string
    {
        return 'product_tour';
    }

    /**
     * Whether a product-tour progress model can be resolved for the user.
     */
    public function supports(object $user): bool
    {
        return ModelResolver::productTour($user) instanceof ProductTourProgressInterface;
    }

    /**
     * Clears the product-tour progress of the user.
     */
    public function erase(ErasureRequest $request): void
    {
        ModelResolver::productTour($request->user)?->clearProductTourSeen();
    }
}
