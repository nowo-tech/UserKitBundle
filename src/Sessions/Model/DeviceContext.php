<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;

use function is_string;
use function strtolower;

/**
 * Framework-free view of the request a session is registered or touched on, given to a
 * {@see DeviceIdProviderInterface}: the client IP as seen by the application (trusted proxies applied), the user agent,
 * the request headers (lower-case names, first value) and the cookies.
 *
 * Built by `SessionClientFactory::deviceContext()` without the session cookie, the remember-me cookies (1.16) and the
 * `Authorization`, `Cookie` and `Proxy-Authorization` headers. Cookies and headers can still carry other secrets, so
 * since 1.16 their values are never stored in a property of this object: they live in an internal {@see \WeakMap}
 * keyed by it, and no dump (VarDumper / `dump()`, `var_export()`, `print_r()`, `(array)` cast, `json_encode()`) shows
 * them (names only through `__debugInfo()`). A `clone` has no headers, cookies or attributes. A device context is
 * request-scoped and cannot be serialized.
 *
 * Since 1.16, the host can name request attributes in `sessions.client.context_attributes` (default: none) to receive
 * their **raw** value, objects included, through {@see attribute()} (for example DeviceIntelligence's `_device`).
 * Nothing is copied unless the host names it, and naming an attribute is the host's decision and responsibility; they
 * are kept in the same holder.
 */
final readonly class DeviceContext
{
    /**
     * @param array<array-key, string> $headers header name => first value (names are lower-cased; kept outside the
     *                                          object's properties)
     * @param array<array-key, string> $cookies cookie name => value (kept outside the object's properties)
     * @param array<string, mixed> $attributes the raw values of the request attributes named in
     *                                         `sessions.client.context_attributes` that the request has (kept outside
     *                                         the object's properties)
     */
    public function __construct(
        public ?string $clientIp = null,
        public string $userAgent = '',
        array $headers = [],
        array $cookies = [],
        array $attributes = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $named = [];
        foreach ($cookies as $name => $value) {
            $named[(string) $name] = $value;
        }
        DeviceContextValues::put($this, $normalized, $named, $attributes);
    }

    /**
     * First value of a request header (case-insensitive name), or null.
     */
    public function header(string $name): ?string
    {
        $value = DeviceContextValues::get($this, DeviceContextValues::HEADERS, strtolower($name));

        return is_string($value) ? $value : null;
    }

    /**
     * Value of a request cookie, or null.
     */
    public function cookie(string $name): ?string
    {
        $value = DeviceContextValues::get($this, DeviceContextValues::COOKIES, $name);

        return is_string($value) ? $value : null;
    }

    /**
     * The raw value (objects included) of a request attribute the host named in `sessions.client.context_attributes`,
     * or null when the name is not allowlisted or the request has no such attribute. Since 1.16.
     */
    public function attribute(string $name): mixed
    {
        return DeviceContextValues::get($this, DeviceContextValues::ATTRIBUTES, $name);
    }

    /**
     * True when the host allowlisted this request attribute and the request has it (even with a null value). Since 1.16.
     */
    public function hasAttribute(string $name): bool
    {
        return DeviceContextValues::has($this, DeviceContextValues::ATTRIBUTES, $name);
    }

    /**
     * A device context is request-scoped: it is never serialized (it can carry cookies and headers).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new InvalidArgumentException('A device context cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new InvalidArgumentException('A device context cannot be unserialized.');
    }

    /**
     * @return array{clientIp: string|null, userAgent: string, headers: list<string>, cookies: list<string>}
     */
    public function __debugInfo(): array
    {
        return [
            'clientIp'  => $this->clientIp,
            'userAgent' => $this->userAgent,
            'headers'   => DeviceContextValues::names($this, DeviceContextValues::HEADERS),
            'cookies'   => DeviceContextValues::names($this, DeviceContextValues::COOKIES),
        ];
    }
}
