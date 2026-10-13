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
    public function getKey(): string
    {
        return 'product_tour';
    }

    public function supports(object $user): bool
    {
        return ModelResolver::productTour($user) instanceof ProductTourProgressInterface;
    }

    public function erase(ErasureRequest $request): void
    {
        ModelResolver::productTour($request->user)?->clearProductTourSeen();
    }
}
