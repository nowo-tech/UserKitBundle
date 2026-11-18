<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\OwnerImage\Pointer;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Stringable;
use Throwable;

use function array_values;
use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.20.0 (`pointer_store: column`): one kind's pointer column, resolved from the Doctrine metadata of the owner entity at
 * each call (nothing is memoised: worker mode with kernel reset off, and a write unit may clear or replace the entity
 * manager between attempts). Values are converted with the mapped DBAL types, so a UUID, integer or string subject
 * column all compare as the subject id string. Internal to {@see ColumnOwnerImagePointerStore}.
 *
 * @internal
 */
final readonly class ColumnPointer
{
    /** Column alias of the subject id in the store's queries. */
    public const SUBJECT = 'user_kit_s';

    /** Column alias of the file id in the store's queries. */
    public const FILE = 'user_kit_f';

    /** Column alias prefix of the identifier columns in the store's queries (`user_kit_i0`, `user_kit_i1`…). */
    public const IDENTIFIER = 'user_kit_i';

    /** Integer subject ids that can be bound to an integer column. */
    private const INTEGER_PATTERN = '/^-?[0-9]{1,18}$/D';

    /** UUID subject ids for a `guid` / `uuid` subject column (PostgreSQL's native uuid refuses anything else). */
    private const UUID_PATTERN = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D';

    /** Integer DBAL types (a `bigint` binds as a string with DBAL 4). */
    private const INTEGER_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT];

    /** UUID DBAL types (`uuid`: symfony/doctrine-bridge). */
    private const UUID_TYPES = [Types::GUID, 'uuid'];

    /**
     * @param ClassMetadata<object> $metadata
     * @param list<string> $identifierColumns quoted identifier columns
     * @param list<string> $identifierFields identifier fields, in the order of `$identifierColumns`
     * @param bool $uniqueSubject whether the subject field is the single identifier or a unique column (one row per
     *                            subject id, so a managed entity may answer a read)
     */
    private function __construct(
        public EntityManagerInterface $manager,
        public ClassMetadata $metadata,
        public string $entity,
        public string $field,
        public string $subjectField,
        public string $table,
        public string $fileColumn,
        public string $subjectColumn,
        public array $identifierColumns,
        public array $identifierFields,
        public bool $uniqueSubject,
    ) {
    }

    /**
     * Resolves `owner_images.kinds.<kind>.pointer` against the entity manager that maps the entity.
     *
     * @param array{entity: class-string, field: string, subject_field: string|null} $mapping
     *
     * @throws LogicException when no entity manager maps the entity, or the entity, field or subject field cannot hold a
     *                        pointer (not the root entity, not a mapped field, an association identifier)
     */
    public static function resolve(ManagerRegistry $doctrine, string $kind, array $mapping): self
    {
        $entity  = $mapping['entity'];
        $manager = $doctrine->getManagerForClass($entity);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('owner_images.kinds.%s.pointer: no Doctrine entity manager maps "%s".', $kind, $entity));
        }
        $metadata = $manager->getClassMetadata($entity);
        if ($metadata->getName() !== $metadata->rootEntityName) {
            throw new LogicException(sprintf('owner_images.kinds.%s.pointer.entity: "%s" must be a root entity (the class of the table).', $kind, $entity));
        }
        $subjectField = $mapping['subject_field'] ?? null;
        if ($subjectField === null) {
            if ($metadata->isIdentifierComposite) {
                throw new LogicException(sprintf('owner_images.kinds.%s.pointer.subject_field: "%s" has a composite identifier, name the field that holds the subject id.', $kind, $entity));
            }
            $subjectField = $metadata->getSingleIdentifierFieldName();
        }
        foreach (['field' => $mapping['field'], 'subject_field' => $subjectField] as $option => $name) {
            if (!$metadata->hasField($name)) {
                throw new LogicException(sprintf('owner_images.kinds.%s.pointer.%s: "%s" is not a mapped field of "%s".', $kind, $option, $name, $entity));
            }
        }
        if ($mapping['field'] === $subjectField || in_array($mapping['field'], $metadata->getIdentifierFieldNames(), true)) {
            throw new LogicException(sprintf('owner_images.kinds.%s.pointer.field: "%s" is the subject or an identifier of "%s".', $kind, $mapping['field'], $entity));
        }
        $identifierFields = array_values($metadata->getIdentifierFieldNames());
        foreach ($identifierFields as $name) {
            if (!$metadata->hasField($name)) {
                throw new LogicException(sprintf('owner_images.kinds.%s.pointer.entity: "%s" is identified by an association, which a pointer column does not support.', $kind, $entity));
            }
        }
        $platform = $manager->getConnection()->getDatabasePlatform();
        $quotes   = $manager->getConfiguration()->getQuoteStrategy();
        $columns  = [];
        foreach ($identifierFields as $name) {
            $columns[] = $quotes->getColumnName($name, $metadata, $platform);
        }

        return new self(
            $manager,
            $metadata,
            $entity,
            $mapping['field'],
            $subjectField,
            $quotes->getTableName($metadata, $platform),
            $quotes->getColumnName($mapping['field'], $metadata, $platform),
            $quotes->getColumnName($subjectField, $metadata, $platform),
            $columns,
            $identifierFields,
            self::unique($metadata, $subjectField),
        );
    }

    /**
     * Whether `$field` holds one value per row: the single identifier, a `unique` column or the only column of a unique
     * constraint.
     *
     * @param ClassMetadata<object> $metadata
     */
    private static function unique(ClassMetadata $metadata, string $field): bool
    {
        if ($metadata->getIdentifierFieldNames() === [$field] || ($metadata->getFieldMapping($field)['unique'] ?? false) === true) {
            return true;
        }
        $column = $metadata->getColumnName($field);
        foreach ($metadata->table['uniqueConstraints'] ?? [] as $constraint) {
            if (($constraint['columns'] ?? null) === [$column] || ($constraint['fields'] ?? null) === [$field]) {
                return true;
            }
        }

        return false;
    }

    public function connection(): Connection
    {
        return $this->manager->getConnection();
    }

    public function platform(): AbstractPlatform
    {
        return $this->connection()->getDatabasePlatform();
    }

    /**
     * Row lock of a read inside the write's transaction (none on SQLite: one writer).
     */
    public function forUpdate(): string
    {
        $platform = $this->platform();

        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
    }

    public function type(string $field): Type
    {
        return Type::getType($this->metadata->getTypeOfField($field) ?? Types::STRING);
    }

    /**
     * The database value of a subject id for the subject column, or null when no row can have it (an integer column
     * and a non-integer id, a UUID column and a malformed UUID): such a subject has no pointer.
     */
    public function subjectValue(string $subjectId): mixed
    {
        $type = $this->type($this->subjectField);
        $name = $this->metadata->getTypeOfField($this->subjectField);
        if (($type->getBindingType() === ParameterType::INTEGER || in_array($name, self::INTEGER_TYPES, true)) && preg_match(self::INTEGER_PATTERN, $subjectId) !== 1) {
            return null;
        }
        if (in_array($name, self::UUID_TYPES, true) && preg_match(self::UUID_PATTERN, $subjectId) !== 1) {
            return null;
        }
        try {
            return $type->convertToDatabaseValue($subjectId, $this->platform());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The database value of a file id for the pointer column.
     *
     * @throws Throwable when the column's type refuses the file id
     */
    public function fileValue(?string $fileId): mixed
    {
        return $fileId === null ? null : $this->type($this->field)->convertToDatabaseValue($fileId, $this->platform());
    }

    /**
     * The PHP value of a file id, as the entity holds it.
     */
    public function filePhpValue(?string $fileId): mixed
    {
        return $this->type($this->field)->convertToPHPValue($this->fileValue($fileId), $this->platform());
    }

    /**
     * A database value of `$field` read back as the string the bundle compares (null when it is no string, integer or
     * stringable value).
     */
    public function read(string $field, mixed $raw): ?string
    {
        return $raw === null ? null : self::text($this->type($field)->convertToPHPValue($raw, $this->platform()));
    }

    /**
     * An entity's field value as a string (null when unset or not a string, integer or stringable value).
     */
    public static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value)            => $value,
            is_int($value)               => (string) $value,
            $value instanceof Stringable => (string) $value,
            default                      => null,
        };
    }

    /**
     * Binding of an `IN (?)` list of the subject column (DBAL 3 and 4).
     *
     * @return list<mixed> the one-element `$types` of a query whose only parameter is that list
     */
    public function subjectListTypes(): array
    {
        $binding = $this->type($this->subjectField)->getBindingType();

        return [$binding === ParameterType::INTEGER ? ArrayParameterType::INTEGER : ($binding === ParameterType::BINARY || $binding === ParameterType::LARGE_OBJECT ? ArrayParameterType::BINARY : ArrayParameterType::STRING)];
    }

    /**
     * A database value of `$field` as its PHP value (bound again with its type).
     */
    public function php(string $field, mixed $raw): mixed
    {
        return $this->type($field)->convertToPHPValue($raw, $this->platform());
    }

    /**
     * The managed, initialised entities of the identity map whose subject id is `$subjectId` (every one when null).
     *
     * @return list<object>
     */
    public function loaded(?string $subjectId = null): array
    {
        $unitOfWork = $this->manager->getUnitOfWork();
        $loaded     = [];
        foreach ($unitOfWork->getIdentityMap()[$this->metadata->rootEntityName] ?? [] as $entity) {
            if (!$entity instanceof $this->entity || $unitOfWork->isUninitializedObject($entity)) {
                continue;
            }
            if ($subjectId === null || self::text($this->metadata->getFieldValue($entity, $this->subjectField)) === $subjectId) {
                $loaded[] = $entity;
            }
        }

        return $loaded;
    }

    /**
     * The subject's own rows among `$rows` (house rule LQ: the SQL match may fold case or pad spaces).
     *
     * @param list<array<string, mixed>> $rows rows with the subject column read as {@see self::SUBJECT}
     *
     * @return list<array<string, mixed>> the rows whose subject is exactly `$subjectId`
     */
    public function exact(array $rows, string $subjectId): array
    {
        $exact = [];
        foreach ($rows as $row) {
            if ($this->read($this->subjectField, $row[self::SUBJECT] ?? null) === $subjectId) {
                $exact[] = $row;
            }
        }

        return $exact;
    }
}
