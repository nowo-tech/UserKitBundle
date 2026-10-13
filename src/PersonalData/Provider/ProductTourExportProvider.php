<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData\Provider;

use DateTimeImmutable;
use Nowo\UserKitBundle\Model\ModelResolver;
use Nowo\UserKitBundle\Model\ProductTourProgressInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * Section `product_tour`: `{seen_at: ?string, seen_pages: list<string>}`.
 */
final class ProductTourExportProvider implements PersonalDataExportProviderInterface
{
    public function getSectionKey(): string
    {
        return 'product_tour';
    }

    public function supports(object $user): bool
    {
        return ModelResolver::productTour($user) instanceof ProductTourProgressInterface;
    }

    /**
     * @return array{seen_at?: ?DateTimeImmutable, seen_pages?: list<string>}
     */
    public function export(object $user): array
    {
        $progress = ModelResolver::productTour($user);
        if (!$progress instanceof ProductTourProgressInterface) {
            return [];
        }

        return [
            'seen_at'    => $progress->getProductTourSeenAt(),
            'seen_pages' => $progress->getProductTourSeenPages(),
        ];
    }
}
