<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Twig;

use Nowo\UserKitBundle\OwnerImage\OwnerImageKinds;
use Nowo\UserKitBundle\OwnerImage\OwnerImagesInterface;
use Nowo\UserKitBundle\OwnerImage\OwnerImageSubjects;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function htmlspecialchars;
use function is_string;
use function sprintf;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Twig helpers of owner images (1.18):
 *
 * - `owner_image(kind, subject_id, size = 'md', alt = '', initials = '?', class = '')`: the image as an `<img>` (the
 *   smallest stored square at least twice the displayed size), or an initials bubble when there is none.
 * - `user_avatar(user, size = 'md', alt = '', class = '')`: `owner_image()` of the `avatar_kind` for a user object
 *   (subject id and initials from {@see OwnerImageSubjects}).
 * - `owner_image_url(kind, subject_id, size = 'lg')`: the URL or null (the UiKit image input's `current_url`).
 * - `owner_image_ready(kind, subject_id)`: whether the signed-in user could upload it now (allowed, bucket usable).
 * - `owner_image_preload(kind, subject_ids)`: loads the pointers of a whole list in one call (prints nothing).
 *
 * `alt` empty (the default) hides the image from assistive technology: use it when the name is printed next to it.
 * Pointers are memoised for the request and cleared at every request boundary ({@see OwnerImageMemoListener}) and on
 * `kernel.reset` (FrankenPHP worker mode, kernel reset off).
 */
final class OwnerImageExtension extends AbstractExtension implements ResetInterface
{
    /** Default `owner_images.twig.sizes` (name => displayed CSS pixels). */
    public const SIZES = ['xs' => 24, 'sm' => 30, 'md' => 36, 'lg' => 56];

    /** CSS block of the markup. */
    public const BLOCK = 'nowo-user-kit-owner-image';

    /** @var array<string, string|null> "kind|subject" => file id (null: none) */
    private array $memo = [];

    /**
     * @param array<string, int> $sizes `owner_images.twig.sizes`
     * @param string $avatarKind `owner_images.avatar_kind`
     */
    public function __construct(
        private readonly OwnerImagesInterface $images,
        private readonly OwnerImageSubjects $subjects,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly array $sizes = self::SIZES,
        private readonly string $avatarKind = 'avatar',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('owner_image', $this->image(...), ['is_safe' => ['html']]),
            new TwigFunction('user_avatar', $this->userAvatar(...), ['is_safe' => ['html']]),
            new TwigFunction('owner_image_url', $this->imageUrl(...)),
            new TwigFunction('owner_image_ready', $this->ready(...)),
            new TwigFunction('owner_image_preload', $this->preload(...)),
        ];
    }

    /**
     * `<img>` or the initials bubble.
     */
    public function image(string $kind, ?string $subjectId, string $size = 'md', string $alt = '', string $initials = '?', string $class = ''): string
    {
        $size    = isset($this->sizes[$size]) ? $size : 'md';
        $pixels  = $this->sizes[$size] ?? self::SIZES['md'];
        $fileId  = $this->fileId($kind, $subjectId);
        $classes = self::BLOCK . ' ' . self::BLOCK . '--' . $size . ($class === '' ? '' : ' ' . $class);
        if ($fileId === null) {
            return sprintf(
                '<span class="%s %s--initials"%s data-owner-image="%s">%s</span>',
                $this->escape($classes),
                self::BLOCK,
                $alt === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . $this->escape($alt) . '"',
                $this->escape($kind),
                $this->escape($initials === '' ? '?' : $initials),
            );
        }

        return sprintf(
            '<img class="%s" src="%s" alt="%s" width="%d" height="%d" loading="lazy" decoding="async" data-owner-image="%s">',
            $this->escape($classes),
            $this->escape($this->images->fileUrl($fileId, $pixels)),
            $this->escape($alt),
            $pixels,
            $pixels,
            $this->escape($kind),
        );
    }

    /**
     * `owner_image()` of the avatar kind for a user object (null: the `?` bubble).
     */
    public function userAvatar(?object $user, string $size = 'md', string $alt = '', string $class = ''): string
    {
        return $this->image($this->avatarKind, $user === null ? null : $this->subjects->idOf($user), $size, $alt, $this->subjects->initialsOf($user), $class);
    }

    /**
     * The image URL for a named size, or null.
     */
    public function imageUrl(string $kind, ?string $subjectId, string $size = 'lg'): ?string
    {
        $fileId = $this->fileId($kind, $subjectId);

        return $fileId === null ? null : $this->images->fileUrl($fileId, $this->sizes[$size] ?? self::SIZES['lg']);
    }

    /**
     * Whether the signed-in user may upload this image now.
     */
    public function ready(string $kind, ?string $subjectId): bool
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface && is_string($subjectId) && $this->images->ready($user, $kind, $subjectId);
    }

    /**
     * Loads the pointers of a list (one pointer-store call); prints nothing.
     *
     * @param iterable<mixed> $subjectIds
     */
    public function preload(string $kind, iterable $subjectIds): string
    {
        $ids = [];
        foreach ($subjectIds as $id) {
            if (OwnerImageKinds::isSubjectId($id) && !isset($this->memo[$kind . '|' . $id])) {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            $found = $this->images->fileIds($kind, $ids);
            foreach ($ids as $id) {
                $this->memo[$kind . '|' . $id] = $found[$id] ?? null;
            }
        }

        return '';
    }

    /**
     * Forgets the memoised pointers (request boundary, `kernel.reset`).
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    private function fileId(string $kind, ?string $subjectId): ?string
    {
        if ($subjectId === null || !OwnerImageKinds::isSubjectId($subjectId)) {
            return null;
        }
        $this->preload($kind, [$subjectId]);

        return $this->memo[$kind . '|' . $subjectId] ?? null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
