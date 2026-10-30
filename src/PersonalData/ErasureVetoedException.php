<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use RuntimeException;

use function implode;
use function in_array;
use function sprintf;

/**
 * Thrown by {@see PersonalDataEraser::erase()} when at least one guard vetoes; nothing was erased.
 */
final class ErasureVetoedException extends RuntimeException
{
    /**
     * Builds the exception from the guard vetoes (their reasons become the message).
     *
     * @param non-empty-list<ErasureVeto> $vetoes
     */
    public function __construct(public readonly array $vetoes)
    {
        parent::__construct(sprintf('Personal data erasure vetoed: %s.', implode(', ', $this->getReasons())));
    }

    /**
     * Returns the reason codes of every veto, in order.
     *
     * @return list<string>
     */
    public function getReasons(): array
    {
        return array_map(static fn (ErasureVeto $veto): string => $veto->reason, $this->vetoes);
    }

    /**
     * Whether one of the vetoes carries the given reason code.
     */
    public function hasReason(string $reason): bool
    {
        return in_array($reason, $this->getReasons(), true);
    }
}
