<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Contributes one section to the personal data export document (GDPR art. 15 / 20).
 *
 * Autoconfigured with the `nowo_user_kit.personal_data.export_provider` tag when
 * `nowo_user_kit.personal_data.enabled` is true. Sections are ordered by tag priority
 * (higher first; `#[AsTaggedItem(priority: …)]` works). Built-in providers use negative priorities,
 * so host sections come first by default.
 */
interface PersonalDataExportProviderInterface
{
    /** Unique key of the section in the `sections` map (e.g. `account`, `ui_preferences`). */
    public function getSectionKey(): string;

    /**
     * Whether this provider contributes a section for the given user.
     */
    public function supports(object $user): bool;

    /**
     * JSON-serialisable data. Nested `DateTimeInterface` values are formatted as ATOM strings and
     * backed enums as their value by {@see PersonalDataExporter}.
     */
    public function export(object $user): mixed;
}
