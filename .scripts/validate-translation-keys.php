<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Validates key parity across required translation locales (REQ-I18N-002).
 */
$translationsDir = dirname(__DIR__) . '/src/Resources/translations';
$domain = 'NowoUserKitBundle';
$requiredLocales = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];

if (!is_dir($translationsDir)) {
    fwrite(STDERR, "ERROR: translations directory not found: {$translationsDir}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function loadYamlFile(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: missing translation file: {$path}\n");
        exit(1);
    }

    try {
        $parsed = Yaml::parseFile($path);
    } catch (\Throwable $exception) {
        fwrite(STDERR, "ERROR: invalid YAML in {$path}: {$exception->getMessage()}\n");
        exit(1);
    }

    if (!is_array($parsed)) {
        fwrite(STDERR, "ERROR: invalid YAML root in {$path}\n");
        exit(1);
    }

    return $parsed;
}

/**
 * @param array<string, mixed> $data
 *
 * @return list<string>
 */
function flattenKeys(array $data, string $prefix = ''): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            $keys = [...$keys, ...flattenKeys($value, $fullKey)];
        } else {
            $keys[] = $fullKey;
        }
    }

    sort($keys);

    return $keys;
}

$referencePath = sprintf('%s/%s.en.yaml', $translationsDir, $domain);
$referenceKeys = flattenKeys(loadYamlFile($referencePath));
$failed = false;

foreach ($requiredLocales as $locale) {
    $path = sprintf('%s/%s.%s.yaml', $translationsDir, $domain, $locale);
    $keys = flattenKeys(loadYamlFile($path));

    $missing = array_diff($referenceKeys, $keys);
    $extra = array_diff($keys, $referenceKeys);

    if ($missing !== [] || $extra !== []) {
        $failed = true;
        fwrite(STDERR, "ERROR: key parity failed for locale '{$locale}'\n");

        if ($missing !== []) {
            fwrite(STDERR, '  Missing keys: ' . implode(', ', $missing) . "\n");
        }

        if ($extra !== []) {
            fwrite(STDERR, '  Extra keys: ' . implode(', ', $extra) . "\n");
        }
    }
}

if ($failed) {
    exit(1);
}

echo '✅ Translation key parity OK for locales: ' . implode(', ', $requiredLocales) . "\n";
