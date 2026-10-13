<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use BackedEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

use function count;
use function in_array;
use function is_string;

/**
 * Product-tour seen tracking (`product_tour_seen_at`, `product_tour_seen_pages`).
 *
 * Override {@see productTourPages()} in the using class to declare the host tour catalog once,
 * instead of passing `$knownPages` on every call.
 */
trait ProductTourProgressTrait
{
    /** When set, every product tour is done / suppressed. */
    #[ORM\Column(name: 'product_tour_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $productTourSeenAt = null;

    /**
     * Tour page ids already completed (raw JSON: filtered on read).
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(name: 'product_tour_seen_pages', type: Types::JSON, nullable: true)]
    private ?array $productTourSeenPages = null;

    public function getProductTourSeenAt(): ?DateTimeImmutable
    {
        return $this->productTourSeenAt;
    }

    public function isProductTourSeen(): bool
    {
        return $this->productTourSeenAt instanceof DateTimeImmutable;
    }

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    public function getProductTourSeenPages(?array $knownPages = null): array
    {
        if ($this->productTourSeenPages === null) {
            return [];
        }

        $known = $this->resolveProductTourPages($knownPages);

        return array_values(array_unique(array_filter(
            $this->productTourSeenPages,
            static fn (mixed $id): bool => is_string($id) && $id !== '' && ($known === [] || in_array($id, $known, true)),
        )));
    }

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function hasSeenPage(string|BackedEnum $page, ?array $knownPages = null): bool
    {
        if ($this->isProductTourSeen()) {
            return true;
        }

        return in_array(self::productTourPageId($page), $this->getProductTourSeenPages($knownPages), true);
    }

    /**
     * Records one page as seen (unknown ids are ignored when a catalog is known); once every
     * catalog page is seen, the global timestamp is set too.
     *
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function markPageSeen(string|BackedEnum $page, ?array $knownPages = null, ?DateTimeImmutable $at = null): static
    {
        $known = $this->resolveProductTourPages($knownPages);
        $id    = self::productTourPageId($page);
        if ($id === '' || ($known !== [] && !in_array($id, $known, true))) {
            return $this;
        }

        $pages = $this->getProductTourSeenPages($known);
        if (!in_array($id, $pages, true)) {
            $pages[] = $id;
        }

        $this->productTourSeenPages = $pages;

        if ($known !== [] && count($pages) >= count($known)) {
            $this->productTourSeenAt ??= $at ?? new DateTimeImmutable();
        }

        return $this;
    }

    /**
     * Marks every tour as seen (the catalog, when known, is stored as the seen page list).
     *
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function markProductTourSeen(?DateTimeImmutable $at = null, ?array $knownPages = null): static
    {
        $this->productTourSeenAt = $at ?? new DateTimeImmutable();

        $known = $this->resolveProductTourPages($knownPages);
        if ($known !== []) {
            $this->productTourSeenPages = $known;
        }

        return $this;
    }

    public function clearProductTourSeen(): static
    {
        $this->productTourSeenAt    = null;
        $this->productTourSeenPages = null;

        return $this;
    }

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    public function getEnabledPages(?array $knownPages = null): array
    {
        $known = $this->requireProductTourPages($knownPages);
        if ($this->isProductTourSeen()) {
            return [];
        }

        return array_values(array_diff($known, $this->getProductTourSeenPages($known)));
    }

    /**
     * @param array<array-key, mixed> $enabledPages
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function syncEnabledPages(array $enabledPages, ?array $knownPages = null, ?DateTimeImmutable $at = null): static
    {
        $known   = $this->requireProductTourPages($knownPages);
        $enabled = [];
        foreach ($enabledPages as $page) {
            if (is_string($page) || $page instanceof BackedEnum) {
                $enabled[] = self::productTourPageId($page);
            }
        }

        $completed = array_values(array_diff($known, $enabled));
        if ($completed === []) {
            return $this->clearProductTourSeen();
        }

        $this->productTourSeenPages = $completed;
        $this->productTourSeenAt    = count($completed) >= count($known) ? ($at ?? new DateTimeImmutable()) : null;

        return $this;
    }

    /**
     * Host tour catalog; empty means "accept any page id". Override in the using class.
     *
     * @return array<array-key, BackedEnum|string>
     */
    protected function productTourPages(): array
    {
        return [];
    }

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    private function resolveProductTourPages(?array $knownPages): array
    {
        $ids = [];
        foreach ($knownPages ?? $this->productTourPages() as $page) {
            $id = self::productTourPageId($page);
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    private function requireProductTourPages(?array $knownPages): array
    {
        $known = $this->resolveProductTourPages($knownPages);
        if ($known === []) {
            throw new LogicException('A product tour page catalog is required: pass $knownPages or override productTourPages().');
        }

        return $known;
    }

    private static function productTourPageId(string|BackedEnum $page): string
    {
        return $page instanceof BackedEnum ? (string) $page->value : $page;
    }
}
