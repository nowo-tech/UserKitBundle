<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage;

use function array_values;
use function is_string;
use function preg_match;

/**
 * The configured kinds (`owner_images.kinds`), and the subject id syntax shared by every kind. Immutable: built once
 * from the configuration (worker safe).
 */
final readonly class OwnerImageKinds
{
    /**
     * Subject id syntax: 1–64 characters `[A-Za-z0-9_.:-]` (UUIDs, ULIDs, integers, slugs). Matched byte for byte: `abc`
     * and `ABC` are two subjects. Nothing is trimmed or cut; anything else is refused.
     */
    public const SUBJECT_ID_PATTERN = '/^[A-Za-z0-9_.:\-]{1,64}$/D';

    /** @var array<string, OwnerImageKind> */
    private array $kinds;

    /**
     * @param iterable<OwnerImageKind> $kinds
     */
    public function __construct(iterable $kinds)
    {
        $map = [];
        foreach ($kinds as $kind) {
            $map[$kind->name] = $kind;
        }
        $this->kinds = $map;
    }

    /**
     * The kind of that name, or null.
     */
    public function get(string $name): ?OwnerImageKind
    {
        return $this->kinds[$name] ?? null;
    }

    /**
     * Every kind, in configuration order.
     *
     * @return list<OwnerImageKind>
     */
    public function all(): array
    {
        return array_values($this->kinds);
    }

    /**
     * The kinds whose subject is a user.
     *
     * @return list<OwnerImageKind>
     */
    public function userKinds(): array
    {
        $kinds = [];
        foreach ($this->kinds as $kind) {
            if ($kind->isUserKind()) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * Whether `$id` is a usable subject id ({@see self::SUBJECT_ID_PATTERN}).
     */
    public static function isSubjectId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::SUBJECT_ID_PATTERN, $id) === 1;
    }
}
