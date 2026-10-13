<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Vetoes an erasure (e.g. "last administrator", "sole owner of a project").
 *
 * Autoconfigured with the `nowo_user_kit.personal_data.erasure_guard` tag. Every guard runs
 * before anything is erased; any veto aborts the erasure.
 */
interface ErasureGuardInterface
{
    /** Returns a veto with a reason, or null to allow the erasure. */
    public function check(ErasureRequest $request): ?ErasureVeto;
}
