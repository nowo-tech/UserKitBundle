<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Model;

use BackedEnum;
use DateTimeImmutable;

/**
 * Product-tour "seen" tracking: a global "all tours done / suppressed" timestamp plus the list
 * of tour page ids the user already completed.
 *
 * `$knownPages` is the host catalog of tour page ids (strings or backed enums). When null, the
 * implementation falls back to its own catalog ({@see ProductTourProgressTrait::productTourPages()}),
 * and an empty catalog means "any page id is accepted".
 */
interface ProductTourProgressInterface
{
    public function getProductTourSeenAt(): ?DateTimeImmutable;

    public function isProductTourSeen(): bool;

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    public function getProductTourSeenPages(?array $knownPages = null): array;

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function hasSeenPage(string|BackedEnum $page, ?array $knownPages = null): bool;

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function markPageSeen(string|BackedEnum $page, ?array $knownPages = null, ?DateTimeImmutable $at = null): static;

    /**
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function markProductTourSeen(?DateTimeImmutable $at = null, ?array $knownPages = null): static;

    public function clearProductTourSeen(): static;

    /**
     * Pages whose tour should still auto-start.
     *
     * @param array<array-key, BackedEnum|string>|null $knownPages
     *
     * @return list<string>
     */
    public function getEnabledPages(?array $knownPages = null): array;

    /**
     * Stores the complement of `$enabledPages` as seen (settings form "replay these tours").
     *
     * @param array<array-key, mixed> $enabledPages
     * @param array<array-key, BackedEnum|string>|null $knownPages
     */
    public function syncEnabledPages(array $enabledPages, ?array $knownPages = null, ?DateTimeImmutable $at = null): static;
}
