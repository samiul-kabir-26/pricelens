<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\Search;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SearchOfferController extends Controller
{
    private const SORTS = ['price', '-price', 'total_price', '-total_price', 'confidence', '-confidence', 'site', '-site', 'observed_at', '-observed_at', 'rank'];

    public function index(Request $request, Search $search): JsonResponse
    {
        $this->authorize('view', $search);
        $this->validateFilters($request);

        $build = function () use ($request, $search) {
            $limit = $this->limit($request, 50);
            $offset = $this->decodeCursor($request->query('cursor'));
            // options.max_results caps the ranked result set (all offers stay stored for diagnostics).
            $max = (int) $search->option('max_results', config('pricelens.defaults.max_results'));
            $limit = max(0, min($limit, $max - $offset));
            $rows = $limit > 0 ? $this->query($request, $search)->skip($offset)->take($limit + 1)->get() : collect();
            if ($offset + $limit >= $max) {
                $rows = $rows->take($limit); // nothing beyond max_results
            }
            $rows->each(fn (Offer $o) => $o->setRelation('search', $search));
            $hasMore = $rows->count() > $limit;

            return [
                'data' => OfferResource::collection($rows->take($limit))->resolve(),
                'meta' => ['stats' => $search->stats, 'next_cursor' => $hasMore ? base64_encode(json_encode(['o' => $offset + $limit], JSON_THROW_ON_ERROR)) : null],
            ];
        };

        // Lists of finished searches are cached until the search is mutated (finalize bumps the version).
        if ($search->isTerminal()) {
            $tenant = $this->tenant();
            $version = (int) Cache::get("{$tenant->cachePrefix()}:search:{$search->id}:offers:v", 0);
            $key = "{$tenant->cachePrefix()}:search:{$search->id}:offers:{$version}:".sha1((string) json_encode($request->query()));
            $payload = Cache::remember($key, now()->addHour(), $build);
        } else {
            $payload = $build();
        }

        return response()->json($payload);
    }

    public function export(Request $request, Search $search): StreamedResponse
    {
        $this->authorize('view', $search);
        $this->validateFilters($request);
        $request->validate(['format' => ['nullable', Rule::in(['csv'])]]);
        $query = $this->query($request, $search);
        $tenant = $this->tenant();

        // The body streams after the middleware stack has returned (and cleared the tenant), so re-bind it.
        return response()->streamDownload(fn () => CurrentTenant::instance()->runAs($tenant, function () use ($query, $search) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open the output stream for the CSV export.');
            }
            fputcsv($out, ['offer_id', 'site_domain', 'site_name', 'title', 'url', 'price', 'list_price', 'shipping', 'total', 'currency',
                'converted_total', 'target_currency', 'availability', 'condition', 'seller', 'gtin', 'mpn', 'sku', 'match_band',
                'match_confidence', 'match_type', 'extraction_method', 'price_outlier', 'observed_at'], ',', '"', '');
            $query->chunk(500, function ($offers) use ($out, $search) {
                foreach ($offers as $o) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $o->ulid, $o->site_domain, $o->site_name, $o->title, $o->url,
                        Money::format($o->price_minor, $o->currency), Money::format($o->list_price_minor, $o->currency),
                        Money::format($o->shipping_minor, $o->currency), Money::format($o->totalMinor(), $o->currency), $o->currency,
                        Money::format($o->converted_total_minor, $search->target_currency), $search->target_currency,
                        $o->availability, $o->condition, $o->seller_name, $o->gtin, $o->mpn, $o->sku, $o->match_band,
                        $o->match_confidence, $o->match_type, $o->extraction_method, $o->price_outlier ? 'true' : 'false',
                        $o->observed_at?->toIso8601ZuluString(),
                    ]), ',', '"', '');
                }
            });
            fclose($out);
        }), "search-{$search->ulid}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralize spreadsheet formula injection in exported cells. */
    public function csvSafe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v) ? "'".$v : $v;
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'band' => ['nullable', 'string', 'max:64'],
            'availability' => ['nullable', 'string', 'max:128'],
            'condition' => ['nullable', 'string', 'max:64'],
            'site' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'include_duplicates' => ['nullable', 'boolean'],
            'include_outliers' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Sort NULLs last regardless of direction.
     *
     * @param  literal-string  $col
     * @return literal-string
     */
    private static function nullsLast(string $col): string
    {
        return "CASE WHEN {$col} IS NULL THEN 1 ELSE 0 END";
    }

    /** @return Builder<Offer> */
    private function query(Request $request, Search $search): Builder
    {
        $defaultBands = $search->option('include_possible', false) ? ['exact', 'likely', 'possible'] : ['exact', 'likely'];
        $bands = $request->filled('band') ? array_intersect(explode(',', (string) $request->query('band')), Offer::BANDS) : $defaultBands;
        $currency = $search->target_currency;

        $q = Offer::query()->where('search_id', $search->id)->whereIn('match_band', $bands ?: $defaultBands);
        if (! $request->filled('band')) {
            $q->where('match_confidence', '>=', (float) $search->option('min_confidence', 0));
        }
        if (! $request->boolean('include_duplicates')) {
            $q->where('is_duplicate', false);
        }
        if ($request->has('include_outliers') && ! $request->boolean('include_outliers')) {
            $q->where('price_outlier', false);
        }
        foreach (['availability', 'condition'] as $f) {
            if ($request->filled($f)) {
                $q->whereIn($f, explode(',', (string) $request->query($f)));
            }
        }
        if ($request->filled('site')) {
            $q->whereIn('site_domain', explode(',', strtolower((string) $request->query('site'))));
        }
        if ($request->filled('min_price')) {
            $q->where('converted_total_minor', '>=', Money::toMinor((string) $request->query('min_price'), $currency));
        }
        if ($request->filled('max_price')) {
            $q->where('converted_total_minor', '<=', Money::toMinor((string) $request->query('max_price'), $currency));
        }

        $sort = (string) $request->query('sort', 'rank');
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        match ($field) {
            'price' => $q->orderByRaw(self::nullsLast('converted_price_minor'))->orderBy('converted_price_minor', $dir),
            'total_price' => $q->orderByRaw(self::nullsLast('converted_total_minor'))->orderBy('converted_total_minor', $dir),
            'confidence' => $q->orderBy('match_confidence', $sort === 'confidence' ? 'desc' : 'asc'),
            'site' => $q->orderBy('site_domain', $dir),
            'observed_at' => $q->orderBy('observed_at', $dir),
            default => $q
                ->orderByRaw("CASE match_band WHEN 'exact' THEN 0 WHEN 'likely' THEN 1 WHEN 'possible' THEN 2 ELSE 3 END")
                ->orderByRaw('price_outlier ASC')
                ->orderByRaw("CASE WHEN availability = 'in_stock' THEN 0 ELSE 1 END")
                ->orderByRaw(self::nullsLast('converted_total_minor'))
                ->orderBy('converted_total_minor'),
        };

        return $q->orderBy('id');
    }

    private function decodeCursor(mixed $cursor): int
    {
        if (! is_string($cursor) || $cursor === '') {
            return 0;
        }
        $data = json_decode((string) base64_decode($cursor, true), true);

        return max(0, (int) ($data['o'] ?? 0));
    }
}
