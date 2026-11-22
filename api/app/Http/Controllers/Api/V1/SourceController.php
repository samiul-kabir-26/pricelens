<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SourceRequest;
use App\Http\Requests\TestSourceRequest;
use App\Http\Resources\SourceResource;
use App\Jobs\RefreshFeedJob;
use App\Models\AuditLog;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Source;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Discovery\DiscovererRegistry;
use App\Scraping\Extraction\ExtractionPipeline;
use App\Scraping\Fetching\Fetcher;
use App\Scraping\Fetching\FetchException;
use App\Scraping\Planning\IdentifierExtractor;
use App\Scraping\Planning\QueryNormalizer;
use App\Scraping\Providers\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['type' => ['nullable', Rule::in(Source::TYPES)], 'enabled' => ['nullable', 'boolean']]);

        $query = Source::query()->where('tenant_id', $this->tenant()->id)
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')));

        return $this->paginate($query, SourceResource::class, $request);
    }

    public function global(Request $request): AnonymousResourceCollection
    {
        $term = $request->query('q');
        $query = Source::query()->where('is_global', true)->whereNull('tenant_id')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when(is_string($term) ? $term : null, fn ($q, string $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('domain', 'like', "%{$s}%")));

        return $this->paginate($query, SourceResource::class, $request);
    }

    public function store(SourceRequest $request): JsonResponse
    {
        $data = $request->sourceData();
        $source = Source::query()->create([
            'tenant_id' => $this->tenant()->id,
            'is_global' => false,
            'type' => $data['type'],
            'name' => $data['name'],
            'domain' => isset($data['domain']) ? strtolower($data['domain']) : null,
            'country' => isset($data['country']) ? strtoupper($data['country']) : null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'render' => $data['render'] ?? 'auto',
            'enabled' => $data['enabled'] ?? true,
            'config' => $data['config'] ?? [],
        ])->refresh();
        $this->forgetCache();
        AuditLog::record('source.created', $source, ['type' => $source->type, 'domain' => $source->domain]);

        if ($source->type === 'feed') {
            RefreshFeedJob::dispatch($this->tenant()->id, $source->id)->onQueue('default');
        }

        return (new SourceResource($source))->response()->setStatusCode(201);
    }

    public function show(Source $source): SourceResource
    {
        $this->authorize('view', $source);

        return new SourceResource($source);
    }

    public function update(SourceRequest $request, Source $source): SourceResource
    {
        $data = $request->sourceData();

        // Tenants may only opt in/out of global adapters.
        if ($source->is_global) {
            $this->authorize('toggle', $source);
            if (array_diff(array_keys($data), ['enabled', 'config']) !== [] || ! array_key_exists('enabled', $data) || ! empty($data['config'])) {
                abort(response()->json([
                    'type' => config('pricelens.errors_base_url').'global_source_read_only', 'title' => 'Global adapters are read-only',
                    'status' => 403, 'detail' => 'Only `enabled` can be changed on a global adapter; fork it to customize.',
                ], 403, ['Content-Type' => 'application/problem+json']));
            }
            DB::table('tenant_global_sources')->updateOrInsert(
                ['tenant_id' => $this->tenant()->id, 'source_id' => $source->id],
                ['enabled' => (bool) $data['enabled'], 'updated_at' => now(), 'created_at' => now()],
            );
            AuditLog::record($data['enabled'] ? 'source.global_enabled' : 'source.global_disabled', $source);
            $this->forgetCache();

            return new SourceResource($source);
        }

        $this->authorize('update', $source);
        if (isset($data['config']) && $request->attributes->get('config_from_flat')) {
            $data['config'] = array_replace_recursive($source->config ?? [], $data['config']);
        }
        $source->fill(collect($data)->only(['name', 'domain', 'country', 'currency', 'render', 'enabled', 'config'])->all())->save();
        $this->forgetCache();
        AuditLog::record('source.updated', $source, ['fields' => array_keys($data)]);

        return new SourceResource($source);
    }

    public function destroy(Source $source): Response
    {
        $this->authorize('delete', $source);
        AuditLog::record('source.deleted', $source, ['domain' => $source->domain]);
        $source->delete();
        $this->forgetCache();

        return response()->noContent();
    }

    public function fork(Source $source): JsonResponse
    {
        $this->authorize('view', $source);

        $fork = $source->replicate(['ulid', 'tenant_id', 'is_global', 'health_status', 'forked_from_id', 'feed_refreshed_at', 'sitemap_refreshed_at']);
        $fork->fill([
            'tenant_id' => $this->tenant()->id,
            'is_global' => false,
            'forked_from_id' => $source->id,
            'name' => $source->name.($source->is_global ? '' : ' (copy)'),
            'health_status' => 'healthy',
            'enabled' => true,
        ])->save();
        $this->forgetCache();
        AuditLog::record('source.forked', $fork, ['from' => $source->ulid]);

        return (new SourceResource($fork))->response()->setStatusCode(201);
    }

    /**
     * Dry-run the adapter against a URL (fetch + extract) or a query (discovery), returning extracted fields,
     * raw selector hits and timing. Used by the UI adapter editor.
     */
    public function test(TestSourceRequest $request, Source $source, Fetcher $fetcher, ExtractionPipeline $pipeline, DiscovererRegistry $discoverers): JsonResponse
    {
        $this->authorize('view', $source);
        $tenant = $this->tenant();
        $started = hrtime(true);
        $out = ['http_status' => null, 'timing_ms' => null, 'extraction_method' => null, 'fields' => (object) [], 'field_sources' => (object) [],
            'selector_hits' => (object) [], 'candidates' => [], 'errors' => []];

        try {
            if ($url = $request->validated('url')) {
                $page = $fetcher->fetch($tenant, $url, $source, ['fresh' => true, 'render' => $request->boolean('render') || $source->render === 'always']);
                $product = $pipeline->run($page->body, $page->finalUrl, $source, $tenant);
                $out['http_status'] = $page->status;
                $out['extraction_method'] = $product->hasPrice() ? $product->method() : null;
                $out['fields'] = (object) $product->fields();
                $out['field_sources'] = (object) $product->fieldSources();
                $out['selector_hits'] = (object) $pipeline->selectorHits;
                if ($reason = ExtractionPipeline::failureReason($product)) {
                    $out['errors'][] = $reason;
                }
            } else {
                $normalizer = app(QueryNormalizer::class);
                $text = (string) $request->validated('query');
                $query = new ProductQuery(title: $text, cleanedTitle: $normalizer->clean($text), tokens: $normalizer->tokens($text),
                    model: app(IdentifierExtractor::class)->model($text));
                $search = new Search(['options' => ['fresh' => true], 'country' => $source->country ?? $tenant->default_country, 'target_currency' => $tenant->default_currency]);
                $plan = $this->testPlan($source, $query);
                $ss = new SearchSource(['type' => $source->type, 'label' => $source->name, 'domain' => $source->domain, 'source_id' => $source->id,
                    'plan' => $plan]);
                $ss->setRelation('source', $source);
                $result = $discoverers->for((string) $plan['strategy'])->discover($tenant, $search, $ss, $query);
                $out['candidates'] = array_map(fn ($c) => $c->url, $result->candidates);
                $out['http_status'] = $result->requests[0]['http_status'] ?? null;
                $out['fields'] = (object) ['direct_offers' => array_map(fn ($o) => $o->product->fields() + ['url' => $o->url], $result->directOffers)];
                if ($result->error) {
                    $out['errors'][] = $result->error;
                }
            }
        } catch (FetchException|ProviderException $e) {
            $out['http_status'] = $e instanceof FetchException ? $e->httpStatus : null;
            $out['errors'][] = $e->errorCode.': '.$e->getMessage();
        }

        $out['timing_ms'] = (int) ((hrtime(true) - $started) / 1e6);

        return response()->json(['data' => $out]);
    }

    /** @return array<string, mixed> */
    private function testPlan(Source $source, ProductQuery $query): array
    {
        $variants = array_values(array_filter([$query->cleanedTitle]));

        return match ($source->type) {
            'search_engine', 'shopping_engine' => ['strategy' => 'engine', 'provider' => $source->cfg('provider'),
                'mode' => $source->type === 'shopping_engine' ? 'shopping' : 'web', 'scope' => $source->cfg('scope', 'open'),
                'domains' => (array) $source->cfg('allowed_domains', []), 'queries' => $variants],
            'feed' => ['strategy' => 'feed'],
            default => ['strategy' => 'site_adapter', 'queries' => $variants],
        };
    }

    private function forgetCache(): void
    {
        Cache::forget($this->tenant()->cachePrefix().':sources');
    }
}
