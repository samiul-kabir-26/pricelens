<?php

namespace App\Support;

final class Url
{
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    /** Display/dedupe domain: lowercase host without a leading `www.`. */
    public static function siteDomain(string $url): ?string
    {
        $host = self::host($url);

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }

    public static function hash(string $url): string
    {
        return sha1($url);
    }

    /**
     * Canonical form for dedupe: lowercase scheme/host, no fragment, no default port, tracking params removed,
     * remaining query params sorted, trailing slash trimmed (except root).
     *
     * @param  array<int, string>|null  $trackingParams  supports `prefix_*` wildcards
     */
    public static function canonicalize(string $url, ?array $trackingParams = null): string
    {
        $trackingParams ??= config('pricelens.scraper.tracking_params', []);
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter($params, fn ($key) => ! self::isTrackingParam((string) $key, $trackingParams), ARRAY_FILTER_USE_KEY);
            ksort($params);
            $query = $params ? '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    /**
     * @param  array<int, string>  $trackingParams
     */
    private static function isTrackingParam(string $key, array $trackingParams): bool
    {
        $key = strtolower($key);
        foreach ($trackingParams as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($key, rtrim($pattern, '*')) : $key === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** Resolve a possibly-relative href against a base URL. */
    public static function absolutize(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|tel|data):/i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $b = parse_url($base);
        if (! $b || ! isset($b['scheme'], $b['host'])) {
            return null;
        }
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($href, '//')) {
            return $b['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        if (str_starts_with($href, '?')) {
            return $origin.($b['path'] ?? '/').$href;
        }

        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        $path = $dir.$href;
        // Normalize ./ and ../ segments.
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }

        return $origin.implode('/', $segments);
    }

    public static function matchesDomain(string $host, string $domain): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        $domain = strtolower(preg_replace('/^www\./', '', $domain) ?? $domain);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
