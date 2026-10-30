<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\PersonalData;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use LogicException;
use Psr\Clock\ClockInterface;
use UnitEnum;

use function array_key_exists;
use function is_array;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Builds the versioned personal data document:
 *
 * ```json
 * {"schema": "…", "version": 1, "exported_at": "2026-10-13T10:00:00+00:00", "sections": {"key": …}}
 * ```
 */
final readonly class PersonalDataExporter
{
    /**
     * Receives the tagged export providers, the clock and the document schema / version.
     *
     * @param iterable<PersonalDataExportProviderInterface> $providers ordered by priority
     */
    public function __construct(
        private iterable $providers,
        private ClockInterface $clock,
        private string $schema = 'nowo-user-kit/personal-data',
        private int $version = 1,
    ) {
    }

    /**
     * Builds the personal data document of a user from every supporting provider.
     *
     * @return array{schema: string, version: int, exported_at: string, sections: array<string, mixed>}
     */
    public function export(object $user): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            if (!$provider->supports($user)) {
                continue;
            }

            $key = $provider->getSectionKey();
            if (array_key_exists($key, $sections)) {
                throw new LogicException(sprintf('Duplicate personal data export section "%s" (%s).', $key, $provider::class));
            }

            $sections[$key] = self::normalize($provider->export($user));
        }

        return [
            'schema'      => $this->schema,
            'version'     => $this->version,
            'exported_at' => $this->clock->now()->format(DateTimeInterface::ATOM),
            'sections'    => $sections,
        ];
    }

    /**
     * Encodes {@see export()} as JSON.
     *
     * @throws JsonException
     */
    public function exportJson(object $user, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->export($user), $flags | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
