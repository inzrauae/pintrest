<?php

namespace App\Support;

/**
 * Produces a canonical form of a URL so equivalent links are detected as duplicates.
 */
class UrlNormalizer
{
    /** Query parameters that only carry tracking data. */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'ref_src', '_ga',
    ];

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/{2,}#', '/', $path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_diff_key($params, array_flip(self::TRACKING_PARAMS));
            ksort($params);
            $query = http_build_query($params);
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '').$path.($query !== '' ? '?'.$query : '');
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    /** Resolves a possibly-relative URL against a base URL. Returns null for non-http(s) results. */
    public static function absolute(?string $href, string $base): ?string
    {
        $href = trim((string) $href);
        if ($href === '' || str_starts_with($href, 'data:') || str_starts_with($href, 'javascript:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $b = parse_url($base);
        if (! $b || empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'https';
        $origin = $scheme.'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null; // other scheme (mailto:, ftp:, ...)
        }

        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir.$href;

        // Collapse ./ and ../ segments.
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
}
