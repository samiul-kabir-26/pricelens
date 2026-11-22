<?php

namespace App\Scraping\Fetching;

use Closure;

/**
 * Single SSRF guard for page fetches, image downloads, feed URLs and webhooks.
 * Rejects non-http(s) schemes, credentials in URLs, and any host resolving to a private, loopback,
 * link-local, CGNAT, multicast, reserved or cloud-metadata address. The caller pins the resolved IP.
 */
class SafeUrlGuard
{
    /** @var array<int, string> */
    public const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  host → IPs (injectable for tests)
     */
    public function __construct(private ?Closure $resolver = null) {}

    public function check(string $url): ResolvedUrl
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme'])) {
            throw new UnsafeUrlException('invalid_url', "Invalid URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('scheme_not_allowed', "Scheme '{$scheme}' is not allowed.");
        }
        if (empty($parts['host'])) {
            throw new UnsafeUrlException('invalid_url', "Invalid URL: {$url}");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('credentials_in_url', 'Credentials in URLs are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (self::isAllowlisted($host)) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
            if ($ips === []) {
                throw new UnsafeUrlException('dns_failed', "Could not resolve host '{$host}'.");
            }

            return new ResolvedUrl($url, $scheme, $host, (int) $port, $ips[0]);
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new UnsafeUrlException('ssrf_blocked', "Host '{$host}' is not allowed.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException('dns_failed', "Could not resolve host '{$host}'.");
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new UnsafeUrlException('ssrf_blocked', "Host '{$host}' resolves to a blocked address.");
            }
        }

        return new ResolvedUrl($url, $scheme, $host, (int) $port, $ips[0]);
    }

    /**
     * Non-production escape hatch for fixture farms / canaries on private networks
     * (PRICELENS_SSRF_ALLOW_HOSTS="*.fixture.test,canary.internal"). Always empty in production.
     */
    public static function isAllowlisted(string $host): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        foreach ((array) config('pricelens.scraper.ssrf_allow_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '*.') ? str_ends_with($host, substr($pattern, 1)) : $host === $pattern) {
                return true;
            }
        }

        return false;
    }

    public function isSafe(string $url): bool
    {
        try {
            $this->check($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // IPv4-mapped / IPv4-compatible IPv6 (::ffff:10.0.0.1) → check the embedded IPv4 address.
        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);
            if ($bin !== false && str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
                return self::isBlockedIp((string) inet_ntop(substr($bin, 12)));
            }
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** @return array<int, string> */
    private function resolve(string $host): array
    {
        if ($this->resolver) {
            return ($this->resolver)($host);
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
