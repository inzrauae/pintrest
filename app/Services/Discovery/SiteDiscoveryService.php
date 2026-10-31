<?php

namespace App\Services\Discovery;

use App\Services\Fetching\SafeHttpClient;
use App\Support\UrlNormalizer;
use DOMDocument;
use DOMXPath;
use Throwable;

/**
 * Finds the content pages of a website so each one can become a Pin.
 *
 * Sources, in order: the given sitemap (index or urlset, optionally gzipped) → sitemaps listed in
 * robots.txt → common sitemap locations → a shallow same-site crawl from the given page.
 * Every request goes through SafeHttpClient, so SSRF rules apply to sitemaps and crawled links too.
 */
class SiteDiscoveryService
{
    private const SITEMAP_MIMES = ['application/xml', 'text/xml', 'application/rss+xml', 'application/x-gzip', 'application/gzip', 'application/octet-stream', 'text/plain'];

    private const COMMON_SITEMAPS = ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap-index.xml'];

    /** Files that are never content pages. */
    private const SKIP_EXTENSIONS = 'jpe?g|png|gif|webp|svg|avif|ico|pdf|zip|gz|rar|mp3|mp4|mov|avi|webm|css|js|json|xml|txt|woff2?|ttf|eot|exe|dmg';

    /** Path segments of pages with no Pin value (accounts, carts, archives, feeds…). */
    private const SKIP_PATHS = '#/(wp-admin|wp-json|wp-login|admin|login|logout|register|signin|signup|account|my-account|cart|basket|checkout|search|feed|rss|tag|tags|author|attachment|comments|privacy-policy|terms|cookie-policy|sitemap|site-map|html-sitemap)(/|$)#i';

    private int $sitemapFetches = 0;

    public function __construct(private readonly SafeHttpClient $http) {}

    /**
     * @param  list<string>  $include  keep only URLs containing one of these (case-insensitive)
     * @param  list<string>  $exclude  drop URLs containing any of these
     * @return array{source:string, urls:list<string>, total_found:int}
     */
    public function discover(string $url, ?int $limit = null, array $include = [], array $exclude = []): array
    {
        $limit = max(1, min($limit ?? (int) config('pinbot.discovery.max_urls'), (int) config('pinbot.discovery.max_urls')));
        $this->sitemapFetches = 0;
        $this->http->assertSafe($url);

        $source = 'sitemap';
        $entries = $this->looksLikeSitemap($url) ? $this->readSitemap($url) : [];

        if ($entries === [] && ! $this->looksLikeSitemap($url)) {
            foreach ($this->candidateSitemaps($url) as $candidate) {
                $entries = array_merge($entries, $this->readSitemap($candidate));
                if ($entries !== []) {
                    break;
                }
            }
        }

        if ($entries === []) {
            $source = 'crawl';
            $entries = $this->crawl($url);
        }

        // Newest content first when the sitemap says so.
        usort($entries, fn ($a, $b) => strcmp($b['lastmod'] ?? '', $a['lastmod'] ?? ''));

        $site = $this->siteKey($url);
        $seen = [];
        $urls = [];
        foreach ($entries as $entry) {
            $loc = $entry['loc'];
            if (! $this->isContentPage($loc, $site) || ! $this->matchesFilters($loc, $include, $exclude)) {
                continue;
            }
            $key = UrlNormalizer::normalize($loc);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $urls[] = $loc;
        }

        return ['source' => $source, 'urls' => array_slice($urls, 0, $limit), 'total_found' => count($urls)];
    }

    /**
     * Pulls page URLs out of pasted text: a plain URL list, a sitemap copied from the browser
     * (URL / lastmod / changefreq / priority columns), or raw sitemap XML. Dates, priorities and
     * other words are ignored, image/file URLs are dropped, and sitemap links are expanded into their pages.
     *
     * @return array{urls:list<string>, ignored:int}
     */
    public function urlsFromText(string $text): array
    {
        // Raw XML: drop namespaced elements (<image:loc>, <video:…>) and all markup/attributes (xmlns URLs), keep text like <loc>.
        $text = preg_replace('#<!\[CDATA\[(.*?)\]\]>#s', '$1', $text);
        $text = preg_replace('#<([a-z0-9]+:[a-z0-9_-]+)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        $text = html_entity_decode(preg_replace('#<[^>]*>#', ' ', $text), ENT_QUOTES | ENT_XML1);

        preg_match_all('#https?://[^\s<>"\'`,;|]+#i', $text, $m);
        $found = array_map(fn ($u) => rtrim($u, '.)'), $m[0]);

        $this->sitemapFetches = 0;
        $seen = [];
        $urls = [];
        $ignored = 0;
        foreach ($found as $url) {
            $pages = $this->looksLikeSitemap($url) ? array_column($this->readSitemapSafely($url), 'loc') : [$url];
            foreach ($pages as $page) {
                $key = UrlNormalizer::normalize($page);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($this->isContentPage($page, $this->siteKey($page))) {
                    $urls[] = $page;
                } else {
                    $ignored++;
                }
            }
        }

        return ['urls' => $urls, 'ignored' => $ignored];
    }

    /** @return list<array{loc:string, lastmod:?string}> */
    private function readSitemapSafely(string $url): array
    {
        try {
            $this->http->assertSafe($url);
        } catch (Throwable) {
            return [];
        }

        return $this->readSitemap($url);
    }

    private function looksLikeSitemap(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz') || str_contains($path, 'sitemap');
    }

    /** @return list<string> */
    private function candidateSitemaps(string $url): array
    {
        $origin = $this->origin($url);
        $found = [];

        try {
            $robots = $this->http->get($origin.'/robots.txt', ['text/plain'], 512 * 1024);
            if (preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots->body, $m)) {
                $found = $m[1];
            }
        } catch (Throwable) {
            // No robots.txt is normal; fall through to common locations.
        }

        return array_values(array_unique(array_merge($found, array_map(fn ($p) => $origin.$p, self::COMMON_SITEMAPS))));
    }

    /**
     * Reads a sitemap or sitemap index (recursively, bounded).
     *
     * @return list<array{loc:string, lastmod:?string}>
     */
    private function readSitemap(string $url, int $depth = 0): array
    {
        if ($depth > 2 || $this->sitemapFetches >= (int) config('pinbot.discovery.max_sitemaps')) {
            return [];
        }
        $this->sitemapFetches++;

        try {
            $result = $this->http->get($url, self::SITEMAP_MIMES, (int) config('pinbot.discovery.max_sitemap_bytes'));
        } catch (Throwable) {
            return [];
        }

        $body = $result->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body, (int) config('pinbot.discovery.max_sitemap_bytes') * 5) ?: '';
        }
        if (! str_contains($body, '<')) {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch external entities/DTDs referenced by the document.
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $entries = [];

        foreach ($xpath->query("//*[local-name()='sitemap']/*[local-name()='loc']") as $loc) {
            $child = trim($loc->textContent);
            if (preg_match('#^https?://#i', $child)) {
                $entries = array_merge($entries, $this->readSitemap($child, $depth + 1));
            }
        }

        foreach ($xpath->query("//*[local-name()='url']") as $node) {
            $loc = trim((string) $xpath->evaluate("string(*[local-name()='loc'])", $node));
            if (preg_match('#^https?://#i', $loc)) {
                $lastmod = trim((string) $xpath->evaluate("string(*[local-name()='lastmod'])", $node));
                $entries[] = ['loc' => $loc, 'lastmod' => $lastmod ?: null];
            }
        }

        return $entries;
    }

    /**
     * Breadth-first crawl of same-site links when no sitemap exists.
     *
     * @return list<array{loc:string, lastmod:null}>
     */
    private function crawl(string $start): array
    {
        $site = $this->siteKey($start);
        $maxPages = (int) config('pinbot.discovery.max_crawl_pages');
        $queue = [[$start, 0]];
        $queued = [UrlNormalizer::normalize($start) => true];
        $found = [];
        $fetched = 0;

        while ($queue && $fetched < $maxPages) {
            [$url, $depth] = array_shift($queue);
            $fetched++;

            try {
                $result = $this->http->get($url, ['text/html', 'application/xhtml+xml']);
            } catch (Throwable) {
                continue;
            }
            $found[] = ['loc' => $result->finalUrl, 'lastmod' => null];

            if ($depth >= 2) {
                continue;
            }

            foreach ($this->links($result->body, $result->finalUrl) as $link) {
                $key = UrlNormalizer::normalize($link);
                if (isset($queued[$key]) || ! $this->isContentPage($link, $site)) {
                    continue;
                }
                $queued[$key] = true;
                $queue[] = [$link, $depth + 1];
            }
        }

        // Links seen but not fetched are still candidate pages.
        foreach ($queue as [$url]) {
            $found[] = ['loc' => $url, 'lastmod' => null];
        }

        return $found;
    }

    /** @return list<string> */
    private function links(string $html, string $base): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $rel = strtolower($a->getAttribute('rel'));
            if (str_contains($rel, 'nofollow')) {
                continue;
            }
            $abs = UrlNormalizer::absolute(preg_replace('/#.*$/', '', $a->getAttribute('href')), $base);
            if ($abs) {
                $links[] = $abs;
            }
        }

        return $links;
    }

    private function isContentPage(string $url, string $site): bool
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if ($this->siteKey($url) !== $site) {
            return false;
        }
        $path = $parts['path'] ?? '/';
        if (preg_match('#\.('.self::SKIP_EXTENSIONS.')$#i', $path) || preg_match(self::SKIP_PATHS, $path)) {
            return false;
        }
        // Paginated archives and comment replies duplicate other pages.
        if (preg_match('#/page/\d+/?$#', $path) || preg_match('/(^|&)(replytocom|share|add-to-cart|s)=/', $parts['query'] ?? '')) {
            return false;
        }

        return true;
    }

    private function matchesFilters(string $url, array $include, array $exclude): bool
    {
        $lower = strtolower($url);
        foreach ($exclude as $term) {
            if ($term !== '' && str_contains($lower, strtolower($term))) {
                return false;
            }
        }
        if ($include === []) {
            return true;
        }
        foreach ($include as $term) {
            if ($term !== '' && str_contains($lower, strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /** Host without "www." so www and bare domains count as the same site. */
    private function siteKey(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return strtolower($p['scheme']).'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }
}
