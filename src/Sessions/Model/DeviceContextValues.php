<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use WeakMap;

use function array_key_exists;
use function array_keys;

/**
 * Holds the request values of each {@see DeviceContext} (headers, cookies and the host-allowlisted request
 * attributes) outside the object, so no dump of the context (VarDumper / `dump()`, `var_export()`, `print_r()`,
 * `(array)` cast, `json_encode()`) can reach them (audit MU-U-L1).
 *
 * Keyed by the context object in a {@see WeakMap}: an entry disappears with its context, so nothing outlives the
 * request in a FrankenPHP worker (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class DeviceContextValues
{
    public const HEADERS = 'headers';

    public const COOKIES = 'cookies';

    public const ATTRIBUTES = 'attributes';

    /** @var WeakMap<DeviceContext, array{headers: array<string, string>, cookies: array<string, string>, attributes: array<string, mixed>}>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the values of a context.
     *
     * @param array<string, string> $headers lower-case header name => first value
     * @param array<string, string> $cookies cookie name => value
     * @param array<string, mixed> $attributes attribute name => raw value
     */
    public static function put(DeviceContext $context, array $headers, array $cookies, array $attributes): void
    {
        // A WeakMap keyed by the per-request VO: each entry dies with its context, nothing crosses requests.
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$context] = [self::HEADERS => $headers, self::COOKIES => $cookies, self::ATTRIBUTES => $attributes]; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * True when the context carries this value (even a null attribute).
     *
     * @param self::ATTRIBUTES|self::COOKIES|self::HEADERS $kind
     */
    public static function has(DeviceContext $context, string $kind, string $name): bool
    {
        return isset(self::$values[$context]) && array_key_exists($name, self::$values[$context][$kind]);
    }

    /**
     * One value, or null when it is absent (or on a `clone`).
     *
     * @param self::ATTRIBUTES|self::COOKIES|self::HEADERS $kind
     */
    public static function get(DeviceContext $context, string $kind, string $name): mixed
    {
        return self::$values[$context][$kind][$name] ?? null;
    }

    /**
     * The names the context carries.
     *
     * @param self::ATTRIBUTES|self::COOKIES|self::HEADERS $kind
     *
     * @return list<string>
     */
    public static function names(DeviceContext $context, string $kind): array
    {
        return isset(self::$values[$context]) ? array_keys(self::$values[$context][$kind]) : [];
    }
}
