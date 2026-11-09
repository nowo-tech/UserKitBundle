<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

/**
 * Builds the personal data document of a user (aliased to {@see PersonalDataExporter}).
 */
interface PersonalDataExporterInterface
{
    /**
     * @return array{schema: string, version: int, exported_at: string, sections: array<string, mixed>}
     */
    public function export(object $user): array;
}
