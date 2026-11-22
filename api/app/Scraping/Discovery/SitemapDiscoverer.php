<?php

namespace App\Scraping\Discovery;

use App\Models\SitemapEntry;
use App\Models\Source;
use App\Scraping\Data\Candidate;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ProductQuery;
use App\Support\Url;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Matches the query against a source's local sitemap index (see SitemapIndexer). No network calls: the
 * candidates' product pages are then fetched by the normal pipeline (robots.txt, throttling, extraction).
 */
class SitemapDiscoverer
{
    public function discover(Source $source, ProductQuery $query, int $limit): DiscoveryResult
    {
        $tokens = self::tokens($query);
        if ($tokens === []) {
            return new DiscoveryResult(error: 'no_query_tokens');
        }

        $entries = $this->match($source, $tokens, true, $limit);
        if ($entries === [] && count($tokens) > 1) {
            // Nothing contains every word: fall back to the best partial matches and let scoring sort them out.
            $entries = $this->match($source, $tokens, false, $limit);
        }

        if ($entries === [] && ! SitemapEntry::query()->where('source_id', $source->id)->exists()) {
            return new DiscoveryResult(error: 'sitemap_not_indexed');
        }

        return new DiscoveryResult(candidates: array_map(
            fn (SitemapEntry $e) => new Candidate($e->url, Url::canonicalize($e->url), $e->title),
            $entries,
        ));
    }

    /** @return array<int, string> */
    public static function tokens(ProductQuery $query): array
    {
        $tokens = [];
        foreach ($query->tokens as $token) {
            $token = mb_strtolower((string) preg_replace('~[^\p{L}\p{N}]+~u', '', (string) $token));
            if (mb_strlen($token) >= 3) {
                $tokens[$token] = true;
            }
        }

        return array_slice(array_keys($tokens), 0, 6);
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, SitemapEntry>
     */
    private function match(Source $source, array $tokens, bool $all, int $limit): array
    {
        $q = SitemapEntry::query()->where('source_id', $source->id);

        if (DB::getDriverName() === 'mysql') {
            // "+tennis* +ball*" (all words, prefix match so "ball" finds "balls") or "tennis* ball*" (any word).
            $expr = implode(' ', array_map(fn ($t) => ($all ? '+' : '').$t.'*', $tokens));
            $q->whereRaw('MATCH(title) AGAINST (? IN BOOLEAN MODE)', [$expr])
                ->orderByRaw('MATCH(title) AGAINST (? IN BOOLEAN MODE) DESC', [$expr]);
        } else {
            $q->where(function (Builder $w) use ($tokens, $all) {
                foreach ($tokens as $t) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%';
                    $all ? $w->where('title', 'like', $like) : $w->orWhere('title', 'like', $like);
                }
            });
        }

        // Shorter titles first among equals: "tennis ball" beats "tennis ball machine spare parts kit".
        return $q->orderByRaw('LENGTH(title)')->limit($limit)->get()->all();
    }
}
