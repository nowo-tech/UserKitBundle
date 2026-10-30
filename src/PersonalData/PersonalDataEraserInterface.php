<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Erases (deletes or anonymises) one kind of personal data of a user (GDPR art. 17).
 *
 * Autoconfigured with the `nowo_user_kit.personal_data.eraser` tag. All supporting erasers run
 * in one Doctrine transaction, ordered by tag priority (higher first). Do not call `flush()` or
 * open transactions: {@see PersonalDataEraser} flushes once, inside the transaction.
 */
interface PersonalDataEraserInterface
{
    /** Stable key reported in the erasure result (e.g. `ui_preferences`). */
    public function getKey(): string;

    /**
     * Whether this eraser handles the given user.
     */
    public function supports(object $user): bool;

    /**
     * Erases this kind of personal data for the user in the request (no flush).
     */
    public function erase(ErasureRequest $request): void;
}
