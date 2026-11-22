<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrackedProductRequest;
use App\Http\Resources\TrackedProductResource;
use App\Jobs\ImportTrackedProductsJob;
use App\Models\PriceSnapshot;
use App\Models\TrackedProduct;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrackedProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = $request->query('q');
        $query = TrackedProduct::query()
            ->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))
            ->when(is_string($term) ? $term : null, fn ($q, string $s) => $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")
                ->orWhere('external_ref', $s)->orWhere('gtin', $s)->orWhere('mpn', $s)));

        $page = $this->paginate($query, TrackedProductResource::class, $request);
        $page->collection?->each(fn (TrackedProductResource $r) => $r->withLatestPrices = true);

        return $page;
    }

    public function store(TrackedProductRequest $request): JsonResponse
    {
        $product = TrackedProduct::query()->create($this->attributes($request->validated()));

        return $this->resource($product)->response()->setStatusCode(201);
    }

    public function show(TrackedProduct $trackedProduct): TrackedProductResource
    {
        $this->authorize('view', $trackedProduct);

        return $this->resource($trackedProduct);
    }

    public function update(TrackedProductRequest $request, TrackedProduct $trackedProduct): TrackedProductResource
    {
        $this->authorize('update', $trackedProduct);
        $trackedProduct->fill($this->attributes($request->validated(), $trackedProduct))->save();

        return $this->resource($trackedProduct);
    }

    public function destroy(TrackedProduct $trackedProduct): Response
    {
        $this->authorize('delete', $trackedProduct);
        $trackedProduct->delete();

        return response()->noContent();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:20480']]);
        $tenant = $this->tenant();
        $path = "{$tenant->storagePrefix()}/imports/".Str::ulid().'.csv';
        Storage::disk(config('filesystems.default'))->put($path, (string) file_get_contents($request->file('file')->getRealPath()));
        ImportTrackedProductsJob::dispatch($tenant->id, $path)->onQueue('default');

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    /**
     * Daily/weekly min/avg/max per site. Short ranges aggregate raw snapshots; ranges over 90 days read the
     * nightly price_daily rollup.
     */
    public function priceHistory(Request $request, TrackedProduct $trackedProduct): JsonResponse
    {
        $this->authorize('view', $trackedProduct);
        $request->validate([
            'site' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'interval' => ['nullable', Rule::in(['day', 'week'])],
        ]);

        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->subDays(30)->startOfDay();
        $interval = (string) $request->query('interval', 'day');
        $sites = $request->filled('site') ? explode(',', strtolower((string) $request->query('site'))) : null;
        $currency = $trackedProduct->currency;

        $useRollup = $from->diffInDays($to) > 90;
        if ($useRollup) {
            $rows = DB::table('price_daily')->where('tenant_id', $trackedProduct->tenant_id)->where('tracked_product_id', $trackedProduct->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->when($sites, fn ($q) => $q->whereIn('site_domain', $sites))
                ->get()->map(fn ($r) => ['date' => Carbon::parse($r->date), 'site' => $r->site_domain, 'min' => $r->min_minor, 'max' => $r->max_minor, 'avg' => $r->avg_minor, 'n' => $r->samples]);
        } else {
            $rows = PriceSnapshot::query()->where('tracked_product_id', $trackedProduct->id)
                ->whereBetween('observed_at', [$from, $to])
                ->when($sites, fn ($q) => $q->whereIn('site_domain', $sites))
                ->limit(100000)->get(['site_domain', 'converted_minor', 'total_minor', 'price_minor', 'observed_at'])
                ->map(function ($s) {
                    $v = $s->converted_minor ?? $s->total_minor ?? $s->price_minor;

                    return ['date' => $s->observed_at, 'site' => $s->site_domain, 'min' => $v, 'max' => $v, 'avg' => $v, 'n' => 1];
                });
        }

        $data = $rows->groupBy(fn ($r) => $r['site'].'|'.($interval === 'week' ? $r['date']->copy()->startOfWeek()->toDateString() : $r['date']->toDateString()))
            ->map(function ($group, $key) use ($currency) {
                [$site, $period] = explode('|', $key);
                $n = $group->sum('n');

                return [
                    'period' => $period,
                    'site_domain' => $site,
                    'min' => Money::format((int) $group->min('min'), $currency),
                    'avg' => Money::format((int) round($group->sum(fn ($r) => $r['avg'] * $r['n']) / max(1, $n)), $currency),
                    'max' => Money::format((int) $group->max('max'), $currency),
                    'samples' => $n,
                    'currency' => $currency,
                ];
            })->sortBy(['period', 'site_domain'])->values();

        return response()->json(['data' => $data, 'meta' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString(),
            'interval' => $interval, 'source' => $useRollup ? 'price_daily' : 'price_snapshots']]);
    }

    private function resource(TrackedProduct $product): TrackedProductResource
    {
        $resource = new TrackedProductResource($product);
        $resource->withLatestPrices = true;

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?TrackedProduct $existing = null): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? $existing->currency ?? $this->tenant()->default_currency));
        $out = collect($data)->only(['external_ref', 'title', 'brand', 'gtin', 'mpn', 'image_url', 'attributes'])->all();
        $out['currency'] = $currency;
        if (array_key_exists('reference_price', $data)) {
            $out['reference_price_minor'] = $data['reference_price'] !== null ? Money::toMinor($data['reference_price'], $currency) : null;
        }
        if (array_key_exists('map_price', $data)) {
            $out['map_price_minor'] = $data['map_price'] !== null ? Money::toMinor($data['map_price'], $currency) : null;
        }
        if (array_key_exists('archived', $data)) {
            $out['archived_at'] = $data['archived'] ? ($existing->archived_at ?? now()) : null;
        }

        return $out;
    }
}
