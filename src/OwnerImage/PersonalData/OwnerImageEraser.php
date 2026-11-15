<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\PersonalData;

use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * GDPR erasure of a user's own owner images (1.18; every `user` kind, e.g. the profile picture): the pointer goes and
 * the file is left to the next storage purge ({@see OwnerImagesInterface::forget()}: no bucket call inside the
 * erasure transaction, no flush). Images of `other` kinds the person uploaded (an organization logo) stay: they belong
 * to the organization.
 */
final readonly class OwnerImageEraser implements PersonalDataEraserInterface
{
    public function __construct(
        private OwnerImagesInterface $images,
        private OwnerImageSubjects $subjects,
    ) {
    }

    public function getKey(): string
    {
        return 'owner_images';
    }

    public function supports(object $user): bool
    {
        return $this->subjects->idOf($user) !== null && $this->images->kinds()->userKinds() !== [];
    }

    public function erase(ErasureRequest $request): void
    {
        $id = $this->subjects->idOf($request->user);
        if ($id === null) {
            return;
        }
        foreach ($this->images->kinds()->userKinds() as $kind) {
            $this->images->forget($kind->name, $id);
        }
    }
}
