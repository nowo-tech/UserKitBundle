<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Integration\OwnerImage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\UserKitBundle\Model\UiPreferences;
use Nowo\UserKitBundle\OwnerImage\Pointer\ColumnPointer;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\CompositeOwner;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\Organization;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\OrganizationProfile;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\SubTeam;
use Nowo\UserKitBundle\Tests\Integration\OwnerImage\Fixtures\Entity\Team;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;

/**
 * 1.20.0 `pointer_store: column`: the Doctrine mapping of each pointer is checked again when the store runs (XML-mapped
 * entities pass the compile-time checks unseen): a mapped root entity, mapped fields, a subject field when the identifier
 * is composite, no association identifier, the pointer column neither the subject nor an identifier.
 */
final class ColumnPointerMappingTest extends OwnerImagesKernelTestCase
{
    private function doctrine(): ManagerRegistry
    {
        $doctrine = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    /**
     * @return iterable<string, array{array{entity: class-string, field: string, subject_field: string|null}, string}>
     */
    public static function badMappings(): iterable
    {
        yield 'not an entity' => [['entity' => stdClass::class, 'field' => 'logo', 'subject_field' => null], 'no Doctrine entity manager maps'];
        yield 'an embeddable' => [['entity' => UiPreferences::class, 'field' => 'theme', 'subject_field' => null], 'no Doctrine entity manager maps'];
        yield 'a child entity' => [['entity' => SubTeam::class, 'field' => 'imageFileId', 'subject_field' => null], 'must be a root entity'];
        yield 'a composite identifier' => [['entity' => CompositeOwner::class, 'field' => 'imageFileId', 'subject_field' => null], 'composite identifier'];
        yield 'an unmapped field' => [['entity' => Organization::class, 'field' => 'nope', 'subject_field' => 'uuid'], 'pointer.field: "nope" is not a mapped field'];
        yield 'an unmapped subject field' => [['entity' => Organization::class, 'field' => 'logoFileId', 'subject_field' => 'nope'], 'pointer.subject_field: "nope" is not a mapped field'];
        yield 'the subject field' => [['entity' => Organization::class, 'field' => 'uuid', 'subject_field' => 'uuid'], 'is the subject or an identifier'];
        yield 'the identifier' => [['entity' => Organization::class, 'field' => 'id', 'subject_field' => 'uuid'], 'is the subject or an identifier'];
        yield 'an association identifier' => [['entity' => OrganizationProfile::class, 'field' => 'coverFileId', 'subject_field' => 'slug'], 'identified by an association'];
    }

    /**
     * @param array{entity: class-string, field: string, subject_field: string|null} $mapping
     */
    #[DataProvider('badMappings')]
    public function testAMappingThatCannotHoldAPointerIsRefused(array $mapping, string $message): void
    {
        $this->boot();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        ColumnPointer::resolve($this->doctrine(), 'kind', $mapping);
    }

    public function testValuesAreConvertedWithTheMappedTypes(): void
    {
        $this->boot();
        $root = ColumnPointer::resolve($this->doctrine(), 'team', ['entity' => Team::class, 'field' => 'imageFileId', 'subject_field' => null]);
        self::assertSame(['id'], $root->identifierFields);
        self::assertSame([ArrayParameterType::INTEGER], $root->subjectListTypes());
        self::assertNull($root->subjectValue('abc'), 'no integer row has that id');

        $composite = ColumnPointer::resolve($this->doctrine(), 'composite', ['entity' => CompositeOwner::class, 'field' => 'imageFileId', 'subject_field' => 'founded']);
        self::assertSame(['tenant', 'code'], $composite->identifierFields);
        self::assertNull($composite->subjectValue('2026-11-18'), 'the date type takes no string: no row has it');
        self::assertNull($composite->read('founded', '2026-11-18'), 'a date is no subject id string');
        self::assertSame([ArrayParameterType::STRING], $composite->subjectListTypes());
        self::assertNull($composite->read('imageFileId', null));
        self::assertFalse($composite->uniqueSubject, 'a date column without a unique key: the database counts the rows');

        // Audit TC-I1: malformed ids never reach a guid / bigint column (PostgreSQL's uuid would fail the query).
        $guid = ColumnPointer::resolve($this->doctrine(), 'guid', ['entity' => CompositeOwner::class, 'field' => 'imageFileId', 'subject_field' => 'reference']);
        self::assertNull($guid->subjectValue('not-a-uuid'));
        self::assertSame('0b6f3c58-2b0c-4c8e-9d43-6f6d5b2b7a11', $guid->subjectValue('0b6f3c58-2b0c-4c8e-9d43-6f6d5b2b7a11'));
        $bigint = ColumnPointer::resolve($this->doctrine(), 'bigint', ['entity' => CompositeOwner::class, 'field' => 'imageFileId', 'subject_field' => 'serial']);
        self::assertNull($bigint->subjectValue('12a'));
        self::assertNotNull($bigint->subjectValue('9007199254740993'));

        // Audit TC-L3: the identity map answers only for one row per subject id.
        self::assertTrue($root->uniqueSubject, 'the single identifier');
        self::assertTrue(ColumnPointer::resolve($this->doctrine(), 'code', ['entity' => Team::class, 'field' => 'imageFileId', 'subject_field' => 'code'])->uniqueSubject, 'a unique column');
        self::assertTrue(ColumnPointer::resolve($this->doctrine(), 'label', ['entity' => Team::class, 'field' => 'imageFileId', 'subject_field' => 'label'])->uniqueSubject, 'a unique constraint');
        self::assertFalse(ColumnPointer::resolve($this->doctrine(), 'tenant', ['entity' => CompositeOwner::class, 'field' => 'imageFileId', 'subject_field' => 'tenant'])->uniqueSubject, 'one part of a composite identifier');

        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'uuid-1';
            }
        };
        self::assertSame(['a', '7', 'uuid-1', null, null], [ColumnPointer::text('a'), ColumnPointer::text(7), ColumnPointer::text($stringable), ColumnPointer::text(1.5), ColumnPointer::text(null)]);
    }
}
