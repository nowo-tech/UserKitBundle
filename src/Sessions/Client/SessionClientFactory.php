<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Sessions\Client;

use InvalidArgumentException;
use Nowo\UserKitBundle\Sessions\Model\SessionClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

use function filter_var;
use function hash_hmac;
use function in_array;
use function intdiv;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_contains;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Builds the {@see SessionClient} of a request with data minimisation (GDPR art. 5(1)(c)):
 *
 * - user agent (`sessions.client.user_agent`): `label` keeps the browser and OS **names** only (no versions, no raw
 *   string); `none` keeps nothing;
 * - IP (`sessions.client.ip`): `truncate` keeps the network (`/24` and `/48` by default), `hash` keeps a keyed
 *   HMAC of the truncated network (`h:` + 16 hex, not displayable, useful only to compare), `none` keeps nothing;
 * - device id: only from the optional host {@see DeviceIdResolverInterface}.
 */
final readonly class SessionClientFactory
{
    public const IP_MODES = ['truncate', 'hash', 'none'];

    public const USER_AGENT_MODES = ['label', 'none'];

    private const BROWSERS = [
        '~\bEdg(?:e|A|iOS)?/~'           => 'Edge',
        '~\b(?:OPR|Opera)/~'             => 'Opera',
        '~\bSamsungBrowser/~'            => 'Samsung Internet',
        '~\b(?:Firefox|FxiOS)/~'         => 'Firefox',
        '~\b(?:Chrome|CriOS|Chromium)/~' => 'Chrome',
        '~\bVersion/[\d.]+.*Safari/~'    => 'Safari',
    ];

    private const SYSTEMS = [
        '~\b(?:iPhone|iPad|iPod)\b~'   => 'iOS',
        '~\bAndroid\b~'                => 'Android',
        '~\bCrOS\b~'                   => 'ChromeOS',
        '~\bWindows\b~'                => 'Windows',
        '~\bMac OS X\b|\bMacintosh\b~' => 'macOS',
        '~\bLinux\b~'                  => 'Linux',
    ];

    /**
     * @param string $ipMode `sessions.client.ip` (truncate | hash | none)
     * @param int $ipv4Prefix `sessions.client.ipv4_prefix` (8, 16 or 24)
     * @param int $ipv6Prefix `sessions.client.ipv6_prefix` (multiple of 8, 8–64)
     * @param string $userAgentMode `sessions.client.user_agent` (label | none)
     * @param string $secret HMAC key of the `hash` mode
     */
    public function __construct(
        private string $ipMode = 'truncate',
        private int $ipv4Prefix = 24,
        private int $ipv6Prefix = 48,
        private string $userAgentMode = 'label',
        private string $secret = '',
        private ?DeviceIdResolverInterface $deviceIds = null,
    ) {
        if (!in_array($ipMode, self::IP_MODES, true) || !in_array($userAgentMode, self::USER_AGENT_MODES, true)) {
            throw new InvalidArgumentException('Invalid session client mode.');
        }
        if (!in_array($ipv4Prefix, [8, 16, 24], true) || $ipv6Prefix % 8 !== 0 || $ipv6Prefix < 8 || $ipv6Prefix > 64) {
            throw new InvalidArgumentException('Invalid session client IP prefix length.');
        }
    }

    /**
     * Client data of a request.
     */
    public function fromRequest(Request $request): SessionClient
    {
        $userAgent = $this->userAgentMode === 'label' ? (string) $request->headers->get('User-Agent', '') : '';

        return new SessionClient(
            $this->match(self::BROWSERS, $userAgent),
            $this->match(self::SYSTEMS, $userAgent),
            $this->ip($request->getClientIp()),
            $this->deviceId($request),
        );
    }

    /**
     * Device id of the request from the host resolver (null without one).
     */
    public function deviceId(Request $request): ?string
    {
        $id = $this->deviceIds?->deviceId($request);

        return $id === null || $id === '' ? null : mb_substr($id, 0, 64);
    }

    /**
     * The IP as stored: `203.0.113.0/24`, `2001:db8:85a3::/48`, `h:<16 hex>` or null.
     */
    public function ip(?string $ip): ?string
    {
        if ($this->ipMode === 'none' || $ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $v6      = str_contains($ip, ':');
        $network = IpUtils::anonymize($ip, $v6 ? 0 : intdiv(32 - $this->ipv4Prefix, 8), $v6 ? intdiv(128 - $this->ipv6Prefix, 8) : 0)
            . sprintf('/%d', $v6 ? $this->ipv6Prefix : $this->ipv4Prefix);

        return $this->ipMode === 'hash' ? 'h:' . substr(hash_hmac('sha256', $network, $this->secret), 0, 16) : $network;
    }

    /**
     * @param array<string, string> $patterns
     */
    private function match(array $patterns, string $userAgent): string
    {
        if ($userAgent === '') {
            return '';
        }
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return '';
    }
}
