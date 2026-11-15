<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Form;

use Nowo\UiKitBundle\Form\ImageCropTypeExtension;
use Nowo\UiKitBundle\Form\ImageInputTypeExtension;
use Nowo\UserKitBundle\OwnerImage\OwnerImageKind;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function class_exists;
use function implode;
use function intdiv;

/**
 * Form field of an owner image (1.18): a FileType (unmapped by default) for PNG, JPEG or WebP that renders as UiKit's
 * image input box (2.47+: the stored image, Replace / Undo, drag and drop) with the client-side crop step (a square, or
 * a circle frame for `circle` kinds, at most `crop_max` px, aiming below the storage's byte limit). Options:
 *
 * - `kind` (required): the owner image kind;
 * - `subject_id` (null: no stored image shown): whose image the box shows;
 * - `crop_max` (default 512): longest side of the browser crop (the server still re-encodes to its own square size).
 *
 * The browser crop is a convenience only: the server checks every upload again ({@see OwnerImagesInterface::upload()}).
 * Without UiKit's image input / crop extensions (UiKit before 2.47) it is a plain file input. Submit the file to your
 * controller and call `OwnerImagesInterface::upload()` with its contents.
 *
 * @extends AbstractType<mixed>
 */
final class OwnerImageType extends AbstractType
{
    /** Accepted types (the server decides by content). */
    public const ACCEPT = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly OwnerImagesInterface $images,
    ) {
    }

    public function getParent(): string
    {
        return FileType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'nowo_user_kit_owner_image';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $images = $this->images;
        $resolver->setRequired('kind');
        $resolver->setAllowedTypes('kind', 'string');
        $resolver->setAllowedValues('kind', static fn (string $kind): bool => $images->kinds()->get($kind) instanceof OwnerImageKind);
        $resolver->setDefaults([
            'subject_id' => null,
            'crop_max'   => 512,
            'mapped'     => false,
            'required'   => false,
            'multiple'   => false,
            'attr'       => ['accept' => implode(',', self::ACCEPT)],
        ]);
        $resolver->setAllowedTypes('subject_id', ['null', 'string']);
        $resolver->setAllowedTypes('crop_max', 'int');
        $resolver->setAllowedValues('crop_max', static fn (int $max): bool => $max >= 64 && $max <= 8192);
        if (class_exists(ImageInputTypeExtension::class) && class_exists(ImageCropTypeExtension::class)) {
            $resolver->setDefault(ImageInputTypeExtension::OPTION, static fn (Options $options): array => [
                'current_url' => static fn (): ?string => $options['subject_id'] === null ? null : $images->url($options['kind'], $options['subject_id']),
            ]);
            $resolver->setDefault(ImageCropTypeExtension::OPTION, static fn (Options $options): array => [
                'shape'  => $images->kinds()->get($options['kind'])->shape ?? OwnerImageKind::SHAPE_SQUARE,
                'aspect' => '1',
                'max'    => $options['crop_max'],
                'format' => 'auto',
                // 5 % under the server limit: the crop's own size steps can land a little over its target.
                'max_bytes' => intdiv($images->maxBytes() * 95, 100),
            ]);
        }
    }
}
