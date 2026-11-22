<?php

namespace App\Scraping\Discovery;

use App\Models\SitemapEntry;
use App\Models\Source;
use App\Scraping\Fetching\FetchException;
use App\Scraping\Fetching\RobotsPolicy;
use App\Scraping\Fetching\SafeHttpClient;
use Illuminate\Support\Carbon;

/**
 * Robots-compliant product discovery for shops whose on-site search is disallowed: walks a site source's
 * `sitemap_urls` (sitemap indexes, .xml or .xml.gz) and indexes product URLs into sitemap_entries, with a
 * searchable title derived from the URL slug.
 *
 * Adapter config:
 *   sitemap_urls          sitemap or sitemap-index URLs
 *   sitemap_child_pattern regex a child sitemap URL (inside an index) must match to be followed
 *   sitemap_url_pattern   regex a page URL must match to be indexed (default: every <loc> of a leaf sitemap)
 */
class SitemapIndexer
{
    /** Words that describe the URL structure rather than the product. */
    private const PATH_NOISE = ['product', 'products', 'p', 'item', 'items', 'dp', 'shop', 'catalog', 'index'];

    public function __construct(private SafeHttpClient $http, private RobotsPolicy $robots) {}

    /**
     * Expand the configured sitemaps into the leaf sitemap URLs to index. Only the configured roots are
     * downloaded here; their children are assumed to be leaves (a child that is itself an index is expanded
     * by indexFile), so large shops aren't downloaded twice.
     *
     * @return array<int, string>
     */
    public function leafSitemaps(Source $source): array
    {
        $max = (int) $source->cfg('sitemap_max_files', config('pricelens.sitemaps.max_files_per_source', 500));
        $childPattern = $this->regex($source->cfg('sitemap_child_pattern'));
        $leaves = [];

        foreach ((array) $source->cfg('sitemap_urls', []) as $root) {
            $xml = $this->download((string) $root);
            if (! self::isIndex($xml)) {
                $leaves[] = (string) $root;

                continue;
            }
            foreach (self::locs($xml) as $child) {
                if ($childPattern === null || preg_match($childPattern, $child) === 1) {
                    $leaves[] = $child;
                }
            }
        }

        return array_slice(array_values(array_unique($leaves)), 0, $max);
    }

    /** Index one leaf sitemap; returns the number of entries written. */
    public function indexFile(Source $source, string $url, Carbon $seenAt, int $depth = 0): int
    {
        $pattern = $this->regex($source->cfg('sitemap_url_pattern'));
        $rows = [];
        $written = 0;

        $xml = $this->download($url);
        if ($depth < 2 && self::isIndex($xml)) {
            $written = 0;
            foreach (self::locs($xml) as $child) {
                $written += $this->indexFile($source, $child, $seenAt, $depth + 1);
            }

            return $written;
        }

        foreach (self::urlEntries($xml) as [$loc, $lastmod]) {
            if ($pattern !== null && preg_match($pattern, $loc) !== 1) {
                continue;
            }
            $title = self::titleFromUrl($loc);
            if (mb_strlen($title) < 3) {
                continue; // id-only URLs (e.g. /i123-s456.html) can't be matched by name
            }
            $rows[] = [
                'source_id' => $source->id,
                'url_hash' => sha1($loc),
                'url' => $loc,
                'title' => $title,
                'lastmod' => $lastmod,
                'seen_at' => $seenAt,
            ];
            if (count($rows) === 500) {
                $written += $this->flush($rows);
                $rows = [];
            }
        }

        return $written + $this->flush($rows);
    }

    /** Drop entries that were not seen in the refresh that started at $seenAt. */
    public function prune(Source $source, Carbon $seenAt): int
    {
        return SitemapEntry::query()->where('source_id', $source->id)->where('seen_at', '<', $seenAt)->delete();
    }

    /** "https://shop.test/products/wilson-tennis-ball-3pcs_16657093580_oi44h8" → "wilson tennis ball 3pcs" */
    public static function titleFromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $path = (string) preg_replace('~\.(html?|php|aspx?)$~i', '', $path);
        $parts = array_values(array_filter(preg_split('~[/\-_+.,\s()]+~u', $path) ?: [], fn ($w) => $w !== ''));
        $words = [];
        $afterId = false;
        foreach ($parts as $i => $word) {
            // Database ids (365017) and letter-prefixed ids (i1225830, s23015724) carry no product words.
            if (preg_match('~^[a-z]?\d{5,}$~i', $word)) {
                $afterId = true;

                continue;
            }
            // A short random suffix right after an id at the end of the slug: "..._166570935804140_oi44h8".
            if ($afterId && $i === count($parts) - 1 && preg_match('~\d~', $word) && preg_match('~[a-z]~i', $word)) {
                continue;
            }
            $afterId = false;
            if (in_array(mb_strtolower($word), self::PATH_NOISE, true)) {
                continue;
            }
            $words[] = $word;
        }

        return mb_substr(implode(' ', $words), 0, 512);
    }

    /** A <sitemapindex>, or a non-standard <urlset> whose every <loc> is another sitemap file (Cartup does this). */
    public static function isIndex(string $xml): bool
    {
        if (str_contains(substr($xml, 0, 2048), '<sitemapindex')) {
            return true;
        }
        $locs = self::locs($xml);

        return $locs !== [] && count(preg_grep('~\.xml(\.gz)?(\?.*)?$~i', $locs) ?: []) === count($locs);
    }

    /** @return array<int, string> */
    public static function locs(string $xml): array
    {
        preg_match_all('~<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>~s', $xml, $m);

        return array_values(array_filter(array_map(fn ($u) => html_entity_decode(trim($u), ENT_XML1 | ENT_QUOTES), $m[1])));
    }

    /** @return \Generator<int, array{0: string, 1: string|null}> */
    public static function urlEntries(string $xml): \Generator
    {
        preg_match_all('~<url>(.*?)</url>~s', $xml, $blocks);
        foreach ($blocks[1] as $block) {
            $loc = self::locs($block)[0] ?? null;
            if (! $loc || ! preg_match('~^https?://~i', $loc)) {
                continue;
            }
            $lastmod = null;
            if (preg_match('~<lastmod>\s*([^<]+?)\s*</lastmod>~', $block, $lm)) {
                try {
                    $lastmod = Carbon::parse($lm[1])->utc()->toDateTimeString();
                } catch (\Throwable) {
                    $lastmod = null;
                }
            }
            yield [$loc, $lastmod];
        }
    }

    /** Fetch a sitemap (robots.txt + SSRF guarded), transparently gunzipping .gz payloads. */
    private function download(string $url): string
    {
        $userAgent = (string) config('pricelens.scraper.user_agent');
        if (! $this->robots->allows($url, $userAgent)) {
            throw new FetchException('skipped_robots', "robots.txt disallows {$url}");
        }

        $response = $this->http->get($url, [
            'headers' => ['User-Agent' => $userAgent, 'Accept' => 'application/xml,text/xml,application/gzip,*/*;q=0.5'],
            'timeout' => 60,
            'max_bytes' => (int) config('pricelens.sitemaps.max_download_bytes', 50 * 1024 * 1024),
        ])['response'];

        if (! $response->successful()) {
            throw new FetchException('http_error', "Sitemap {$url} returned HTTP {$response->status()}", retryable: $response->status() >= 500 || $response->status() === 429, httpStatus: $response->status());
        }

        $body = $response->body();
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body, (int) config('pricelens.sitemaps.max_xml_bytes', 200 * 1024 * 1024));
            if ($body === false) {
                throw new FetchException('parse_error', "Sitemap {$url} is not valid gzip.");
            }
        }

        return $body;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        SitemapEntry::query()->upsert($rows, ['source_id', 'url_hash'], ['url', 'title', 'lastmod', 'seen_at']);

        return count($rows);
    }

    private function regex(mixed $pattern): ?string
    {
        return is_string($pattern) && $pattern !== '' ? '~'.str_replace('~', '\~', $pattern).'~i' : null;
    }
}
