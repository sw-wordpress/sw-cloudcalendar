<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Security;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;

/**
 * SSRF policy for user configured URLs (ICS feeds, ICS ATTACH URLs). Checked for the initial URL and every redirect.
 */
final class UrlGuard
{
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver Host name => IP addresses (injectable for tests).
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'resolve'];
    }

    /**
     * Validates the URL and returns the vetted IP addresses of its host.
     *
     * @param list<string>|null $allowedHosts Exact host allowlist for cloud provider endpoints.
     * @return list<string>
     * @throws CalendarException
     */
    public function check(string $url, bool $allowHttp = false, ?array $allowedHosts = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The URL is not valid.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new CalendarException(ErrorCode::FetchFailed, 'Only HTTPS URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new CalendarException(ErrorCode::FetchFailed, 'Credentials inside URLs are not allowed.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($allowedHosts !== null && !self::hostAllowed($host, $allowedHosts)) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The host is not an allowed provider endpoint.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The port is not allowed.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new CalendarException(ErrorCode::FetchFailed, 'Local host names are not allowed.');
        }
        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : ($this->resolver)($host);
        if ($ips === []) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The host name could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new CalendarException(ErrorCode::FetchFailed, 'The host resolves to a private or reserved address.');
            }
        }
        return $ips;
    }

    /**
     * @param list<string> $allowedHosts Exact host names or suffixes starting with a dot (".googleusercontent.com").
     */
    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }
        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) must be judged by its IPv4 part.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Ranges PHP does not classify as private/reserved: CGNAT, benchmarking, unique local/site local IPv6, 6to4/Teredo.
        $blocked = ['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '0.0.0.0/8'];
        foreach ($blocked as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        $lower = strtolower($ip);
        foreach (['fc', 'fd', 'fe8', 'fe9', 'fea', 'feb', 'fec', 'fed', 'fee', 'fef', '2002:', '2001:0:', '2001::', '64:ff9b'] as $prefix) {
            if (str_contains($lower, ':') && str_starts_with($lower, $prefix)) {
                return false;
            }
        }
        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($ip, '.')) {
            return false;
        }
        [$subnet, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }

    /**
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host); // phpcs:ignore -- resolver failure is reported as "not resolvable".
        if (is_array($v4)) {
            $ips = $v4;
        }
        $records = @dns_get_record($host, DNS_AAAA); // phpcs:ignore -- see above.
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }
}
