<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\DependencyInjection;

use Doctrine\ORM\Mapping as ORM;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnOwnerImagePointerStore;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function class_exists;
use function ltrim;
use function sprintf;
use function strtolower;

/**
 * 1.20.0: compile-time checks of `owner_images.kinds.*.pointer` ({@see ColumnOwnerImagePointerStore}). With
 * `pointer_store: column` every kind names its column; the entity class exists and declares the field and the subject
 * field (on an attribute-mapped entity as `#[ORM\Column]` properties); no two kinds share one column (one kind's write
 * would replace the other's image). The store checks the Doctrine mapping again when it runs (XML-mapped entities).
 */
final class ColumnPointers
{
    /**
     * @param array<int|string, array{pointer?: array{entity: string, field: string, subject_field: string|null}}> $kinds
     *
     * @throws InvalidConfigurationException
     *
     * @return array<string, array{entity: class-string, field: string, subject_field: string|null}> kind => pointer column
     */
    public static function fromConfig(array $kinds, bool $column): array
    {
        $pointers = [];
        $columns  = [];
        foreach ($kinds as $name => $kind) {
            $name    = (string) $name;
            $pointer = $kind['pointer'] ?? null;
            if (!$column) {
                if ($pointer !== null) {
                    throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.kinds.%s.pointer is only used with owner_images.pointer_store: column.', $name));
                }

                continue;
            }
            if ($pointer === null) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.pointer_store: column: kind "%s" has no pointer (entity and field).', $name));
            }
            $entity = ltrim($pointer['entity'], '\\');
            if (!class_exists($entity)) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.kinds.%s.pointer.entity: class "%s" does not exist.', $name, $entity));
            }
            self::assertMapped($name, $entity, $pointer['field'], $pointer['subject_field']);
            $key = strtolower($entity) . '::' . $pointer['field'];
            if (isset($columns[$key])) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.kinds: "%s" and "%s" point at the same column %s::$%s.', $columns[$key], $name, $entity, $pointer['field']));
            }
            $columns[$key]   = $name;
            $pointers[$name] = ['entity' => $entity, 'field' => $pointer['field'], 'subject_field' => $pointer['subject_field']];
        }

        return $pointers;
    }

    /**
     * @param class-string $entity
     *
     * @throws InvalidConfigurationException
     */
    private static function assertMapped(string $kind, string $entity, string $field, ?string $subjectField): void
    {
        $class      = new ReflectionClass($entity);
        $attributes = $class->getAttributes(ORM\Entity::class) !== [];
        foreach (['field' => $field, 'subject_field' => $subjectField] as $option => $name) {
            if ($name === null) {
                continue;
            }
            $property = self::property($class, $name);
            if (!$property instanceof ReflectionProperty) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.kinds.%s.pointer.%s: "%s" has no property "%s".', $kind, $option, $entity, $name));
            }
            if ($attributes && $property->getAttributes(ORM\Column::class) === []) {
                throw new InvalidConfigurationException(sprintf('nowo_user_kit.owner_images.kinds.%s.pointer.%s: %s::$%s is not a mapped column (#[ORM\Column]).', $kind, $option, $entity, $name));
            }
        }
    }

    /**
     * The property of that name, also a private one of a parent class.
     *
     * @param ReflectionClass<object> $class
     */
    private static function property(ReflectionClass $class, string $name): ?ReflectionProperty
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if ($current->hasProperty($name)) {
                return $current->getProperty($name);
            }
        }

        return null;
    }
}
