<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\PersonalData;

use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * GDPR export section `owner_images` (1.18): for each `user` kind with an image, its file id and URL (the largest
 * square). The bytes stay in the storage: the URL serves them to the person.
 */
final readonly class OwnerImageExportProvider implements PersonalDataExportProviderInterface
{
    /** Displayed width asked for the exported URL (the largest square). */
    public const EXPORT_PIXELS = 4096;

    public function __construct(
        private OwnerImagesInterface $images,
        private OwnerImageSubjects $subjects,
    ) {
    }

    public function getSectionKey(): string
    {
        return 'owner_images';
    }

    public function supports(object $user): bool
    {
        return $this->subjects->idOf($user) !== null && $this->images->kinds()->userKinds() !== [];
    }

    /**
     * @return array<string, array{file: string, url: string}>
     */
    public function export(object $user): array
    {
        $id      = (string) $this->subjects->idOf($user);
        $section = [];
        foreach ($this->images->kinds()->userKinds() as $kind) {
            $fileId = $this->images->fileIds($kind->name, [$id])[$id] ?? null;
            if ($fileId !== null) {
                $section[$kind->name] = ['file' => $fileId, 'url' => $this->images->fileUrl($fileId, self::EXPORT_PIXELS)];
            }
        }

        return $section;
    }
}
