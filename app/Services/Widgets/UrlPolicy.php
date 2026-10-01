<?php

namespace App\Services\Widgets;

/**
 * The URL rules every widget fetch goes through: what a fetchable URL may
 * look like (https, default port, no userinfo), prefix allowlist matching
 * on the *parsed* URL, and which resolved IP addresses count as public.
 */
class UrlPolicy
{
    /**
     * Canonical form of a user/manifest URL, or null if it isn't one we'd
     * ever fetch. webcal:// (how calendar apps advertise ICS feeds) is
     * treated as https://.
     *
     * $strictPath (the default, used wherever an allowlist prefix is
     * matched) also rejects percent-encoded slashes/dots, which could make a
     * path look like it's under a prefix while meaning something else to
     * the upstream. Fixed-host endpoint URLs — where parameters are
     * percent-encoded into the path on purpose — use the lenient form.
     */
    public static function normalize(string $url, bool $strictPath = true): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        if (preg_match('#^webcals?://#i', $url)) {
            $url = preg_replace('#^webcals?://#i', 'https://', $url);
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower($parts['host']);
        // Bracketed IPv6 literals and bare IPs are allowed through here and
        // rejected (if private) by the resolver check; hostnames must be
        // plain DNS names.
        if (!str_starts_with($host, '[') && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.?$/', $host)) {
            return null;
        }
        $host = rtrim($host, '.');

        $path = self::normalizePath($parts['path'] ?? '/', $strictPath);
        if ($path === null) {
            return null;
        }

        return 'https://' . $host . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Resolves dot segments; rejects encoded slashes/dots that could make a
     * path *look* like it's under an allowed prefix while meaning something
     * else to the upstream server.
     */
    private static function normalizePath(string $path, bool $strict): ?string
    {
        if (preg_match($strict ? '/%(2f|5c|2e|00)/i' : '/%00/', $path)) {
            return null;
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($out) <= 1) {
                    return null;
                }
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $normalized = implode('/', $out);

        return str_starts_with($normalized, '/') ? $normalized : '/' . $normalized;
    }

    public static function host(string $normalizedUrl): string
    {
        return (string) parse_url($normalizedUrl, PHP_URL_HOST);
    }

    /**
     * True when a normalized URL is under one of the allowed prefixes:
     * exact host match, and the path starts with the prefix's path at a
     * segment boundary (or the prefix itself ends with '/').
     *
     * @param  string[]|null  $allow  null = no allowlist (anything passes)
     */
    public static function allowed(string $normalizedUrl, ?array $allow): bool
    {
        if ($allow === null) {
            return true;
        }
        $host = self::host($normalizedUrl);
        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);

        foreach ($allow as $prefix) {
            $p = self::normalize($prefix);
            if ($p === null || self::host($p) !== $host) {
                continue;
            }
            $prefixPath = (string) parse_url($p, PHP_URL_PATH);
            if ($prefixPath === '/' || $path === $prefixPath
                || str_starts_with($path, str_ends_with($prefixPath, '/') ? $prefixPath : $prefixPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Only globally routable unicast addresses are fetchable. IPv4-mapped
     * IPv6 (::ffff:a.b.c.d) is judged by its embedded IPv4 address.
     */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return self::isPublicIp(inet_ntop(substr($bin, 12)));
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (strlen($bin) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
