<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Tests\Unit\PersonalData;

use DateTimeImmutable;
use LogicException;
use Nowo\UserKitBundle\Model\Preference\Theme;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Clock\MockClock;

use const JSON_UNESCAPED_SLASHES;

final class PersonalDataExporterTest extends TestCase
{
    public function testDocumentShapeAndNormalization(): void
    {
        $exporter = new PersonalDataExporter([
            $this->provider('a', ['at' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'), 'nested' => [Theme::Dark, UnitCase::One, 3]]),
            $this->provider('skipped', 'x', false),
            $this->provider('b', 'plain'),
        ], new MockClock('2026-10-13T10:00:00+00:00'));

        $this->assertSame([
            'schema'      => 'nowo-user-kit/personal-data',
            'version'     => 1,
            'exported_at' => '2026-10-13T10:00:00+00:00',
            'sections'    => [
                'a' => ['at' => '2026-01-02T03:04:05+00:00', 'nested' => ['dark', 'One', 3]],
                'b' => 'plain',
            ],
        ], $exporter->export(new stdClass()));

        $this->assertStringContainsString('"schema":"nowo-user-kit/personal-data"', $exporter->exportJson(new stdClass(), JSON_UNESCAPED_SLASHES));
    }

    public function testCustomSchemaAndVersion(): void
    {
        $document = (new PersonalDataExporter([], new MockClock(), 'app-export', 3))->export(new stdClass());

        $this->assertSame('app-export', $document['schema']);
        $this->assertSame(3, $document['version']);
        $this->assertSame([], $document['sections']);
    }

    public function testDuplicateSectionKeysAreRejected(): void
    {
        $exporter = new PersonalDataExporter([$this->provider('a', 1), $this->provider('a', null)], new MockClock());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate personal data export section "a"');
        $exporter->export(new stdClass());
    }

    private function provider(string $key, mixed $data, bool $supports = true): PersonalDataExportProviderInterface
    {
        return new class($key, $data, $supports) implements PersonalDataExportProviderInterface {
            public function __construct(private readonly string $key, private readonly mixed $data, private readonly bool $supports)
            {
            }

            public function getSectionKey(): string
            {
                return $this->key;
            }

            public function supports(object $user): bool
            {
                return $this->supports;
            }

            public function export(object $user): mixed
            {
                return $this->data;
            }
        };
    }
}

enum UnitCase
{
    case One;
}
