<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Erases the personal data of a user: guards, then every supporting eraser (aliased to {@see PersonalDataEraser};
 * {@see PersonalDataEraserInterface} is the per-kind eraser SPI, not this).
 */
interface PersonalDataErasureInterface
{
    /**
     * Every veto, nothing erased.
     *
     * @param array<string, mixed> $context
     *
     * @return list<ErasureVeto>
     */
    public function check(object $user, ?object $actor = null, array $context = []): array;

    /**
     * Runs the guards, then every supporting eraser.
     *
     * @param array<string, mixed> $context
     *
     * @throws ErasureVetoedException when a guard vetoes (nothing is erased)
     *
     * @return list<string> keys of the erasers that ran, in order
     */
    public function erase(object $user, ?object $actor = null, array $context = []): array;
}
