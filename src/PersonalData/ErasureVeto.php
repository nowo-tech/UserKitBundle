<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Reason why an erasure is refused: a stable code (for flash messages / translations) plus details.
 */
final readonly class ErasureVeto
{
    /**
     * @param array<string, mixed> $details e.g. `['projects' => [['uuid' => …, 'name' => …]]]`
     */
    public function __construct(
        public string $reason,
        public string $message = '',
        public array $details = [],
    ) {
    }
}
