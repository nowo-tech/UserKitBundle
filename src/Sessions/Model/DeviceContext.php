<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Model;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;

use function array_keys;
use function strtolower;

/**
 * Framework-free view of the request a session is registered or touched on, given to a
 * {@see DeviceIdProviderInterface}: the client IP as seen by the application (trusted proxies applied), the user agent,
 * the request headers (lower-case names, first value) and the cookies.
 *
 * Built by `SessionClientFactory::deviceContext()` without the session cookie and the `Authorization`, `Cookie` and
 * `Proxy-Authorization` headers. Cookies and headers can still carry other secrets: dumps show their names only, and a
 * device context is request-scoped and cannot be serialized.
 *
 * Since 1.16, the host can name request attributes in `sessions.client.context_attributes` (default: none) to receive
 * their **raw** value, objects included, through {@see attribute()} (for example DeviceIntelligence's `_device`).
 * Nothing is copied unless the host names it, and naming an attribute is the host's decision and responsibility. These
 * values are never stored in a property of this object (they live in an internal {@see \WeakMap} keyed by it), so no
 * dump, `var_export()`, `(array)` cast or `json_encode()` of the context shows them; a `clone` has none.
 */
final readonly class DeviceContext
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers header name => first value (names are lower-cased)
     * @param array<string, string> $cookies cookie name => value
     * @param array<string, mixed> $attributes the raw values of the request attributes named in
     *                                         `sessions.client.context_attributes` that the request has (kept outside
     *                                         the object's properties)
     */
    public function __construct(
        public ?string $clientIp = null,
        public string $userAgent = '',
        array $headers = [],
        private array $cookies = [],
        array $attributes = [],
    ) {
        if ($attributes !== []) {
            DeviceContextAttributes::put($this, $attributes);
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalized;
    }

    /**
     * First value of a request header (case-insensitive name), or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Value of a request cookie, or null.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * The raw value (objects included) of a request attribute the host named in `sessions.client.context_attributes`,
     * or null when the name is not allowlisted or the request has no such attribute. Since 1.16.
     */
    public function attribute(string $name): mixed
    {
        return DeviceContextAttributes::get($this, $name);
    }

    /**
     * True when the host allowlisted this request attribute and the request has it (even with a null value). Since 1.16.
     */
    public function hasAttribute(string $name): bool
    {
        return DeviceContextAttributes::has($this, $name);
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
            'headers'   => array_keys($this->headers),
            'cookies'   => array_keys($this->cookies),
        ];
    }
}
