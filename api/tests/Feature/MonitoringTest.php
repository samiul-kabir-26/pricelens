<?php

use App\Jobs\DispatchDueMonitorsJob;
use App\Jobs\PlanSearchJob;
use App\Models\Alert;
use App\Models\Monitor;
use App\Models\PriceSnapshot;
use App\Models\Search;
use App\Models\TrackedProduct;
use App\Monitoring\AlertEvaluator;
use App\Services\MonitorRunner;
use App\Services\SearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => fakePublicDns());

it('creates tracked products and monitors with computed next run', function () {
    [, $key] = tenantWithKey();

    $product = $this->postJson('/api/v1/tracked-products', [
        'external_ref' => 'SKU-1', 'title' => 'Sony WH-1000XM5', 'gtin' => '0027242923782', 'map_price' => 299, 'currency' => 'USD',
    ], bearer($key))->assertCreated()->assertJsonPath('data.map_price', ['amount' => '299.00', 'currency' => 'USD'])->json('data');

    $this->postJson('/api/v1/tracked-products', ['external_ref' => 'SKU-1', 'title' => 'dup'], bearer($key))->assertStatus(422);

    $monitor = $this->postJson('/api/v1/monitors', [
        'tracked_product_id' => $product['id'], 'schedule' => '0 */6 * * *', 'timezone' => 'Europe/Berlin',
        'alert_rules' => ['price_drop_percent' => 10, 'map_enforcement' => true, 'cooldown_minutes' => 720],
    ], bearer($key))->assertCreated()->json('data');

    expect($monitor['status'])->toBe('active')->and($monitor['next_run_at'])->not->toBeNull();
    $this->postJson('/api/v1/monitors', ['tracked_product_id' => $product['id'], 'schedule' => '* * * * *'], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['schedule']]);
    $this->getJson("/api/v1/monitors?tracked_product_id={$product['id']}", bearer($key))->assertJsonCount(1, 'data');
});

it('enforces the active-monitor limit', function () {
    [$tenant, $key] = tenantWithKey('starter', settings: ['limits' => ['active_monitors' => 1]]);
    $p = $this->postJson('/api/v1/tracked-products', ['title' => 'A'], bearer($key))->json('data.id');

    $this->postJson('/api/v1/monitors', ['tracked_product_id' => $p, 'schedule' => '0 * * * *'], bearer($key))->assertCreated();
    $this->postJson('/api/v1/monitors', ['tracked_product_id' => $p, 'schedule' => '0 * * * *'], bearer($key))->assertStatus(429);
});

it('dispatches due monitors and advances next_run_at', function () {
    Queue::fake();
    [$tenant] = tenantWithKey();
    $monitor = asTenant($tenant, function () {
        $p = TrackedProduct::query()->create(['title' => 'Sony WH-1000XM5', 'currency' => 'EUR', 'gtin' => '0027242923782']);

        return Monitor::query()->create(['tracked_product_id' => $p->id, 'schedule' => '0 * * * *', 'status' => 'active', 'next_run_at' => now()->subMinute()]);
    });

    (new DispatchDueMonitorsJob)->handle(app(MonitorRunner::class));

    $search = Search::acrossTenants()->where('monitor_id', $monitor->id)->first();
    expect($search)->not->toBeNull()->and($search->target_currency)->toBe('EUR')->and($search->input['gtin'])->toBe('0027242923782')
        ->and($monitor->fresh()->next_run_at->isFuture())->toBeTrue();
    Queue::assertPushed(PlanSearchJob::class, 1);
});

it('writes snapshots and raises alerts after a monitor search', function () {
    [$tenant, $key] = tenantWithKey();
    Http::fake([
        'shop.example.com/robots.txt' => Http::response('', 404),
        'shop.example.com/*' => Http::response(htmlFixture('jsonld-graph-aggregate.html')),
    ]);
    $monitor = asTenant($tenant, function () {
        $p = TrackedProduct::query()->create(['title' => 'Sony WH-1000XM5', 'currency' => 'USD', 'gtin' => '0027242923782', 'map_price_minor' => 34900]);
        $m = Monitor::query()->create(['tracked_product_id' => $p->id, 'schedule' => '0 * * * *', 'status' => 'active',
            'options' => ['sources' => ['domains' => []]],
            'alert_rules' => ['price_drop_percent' => 10, 'map_enforcement' => true, 'cooldown_minutes' => 720]]);
        // previous observation at a higher price
        PriceSnapshot::query()->create(['tracked_product_id' => $p->id, 'site_domain' => 'shop.example.com', 'url_hash' => sha1('x'),
            'price_minor' => 39999, 'total_minor' => 39999, 'currency' => 'USD', 'converted_minor' => 39999, 'availability' => 'in_stock', 'observed_at' => now()->subDay()]);
        $p->update(['image_url' => null]);

        return $m;
    });

    // Monitor search via reference URL for determinism.
    asTenant($tenant, fn () => app(SearchService::class)->create($tenant,
        ['gtin' => '0027242923782', 'reference_url' => 'https://shop.example.com/p/sony-wh-1000xm5-black'], ['target_currency' => 'USD'], monitor: $monitor));

    $productUlid = asTenant($tenant, fn () => $monitor->trackedProduct->ulid);
    $history = $this->getJson("/api/v1/tracked-products/{$productUlid}/price-history?interval=day", bearer($key))->assertOk()->json('data');
    expect(collect($history)->pluck('min'))->toContain('329.99', '399.99');

    $types = Alert::acrossTenants()->where('monitor_id', $monitor->id)->pluck('type')->all();
    expect($types)->toContain('price_drop', 'map_violation');

    $this->getJson("/api/v1/tracked-products/{$productUlid}", bearer($key))
        ->assertJsonPath('data.latest_prices.0.price.amount', '329.99');
    $alertId = $this->getJson('/api/v1/alerts?type=map_violation', bearer($key))->assertJsonCount(1, 'data')->json('data.0.id');
    $this->postJson("/api/v1/alerts/{$alertId}/acknowledge", [], bearer($key))->assertOk()->assertJsonPath('data.acknowledged_at', fn ($v) => $v !== null);
});

it('respects alert cooldowns and availability transitions', function () {
    [$tenant] = tenantWithKey();
    asTenant($tenant, function () use ($tenant) {
        $p = TrackedProduct::query()->create(['title' => 'X', 'currency' => 'USD']);
        $m = Monitor::query()->create(['tracked_product_id' => $p->id, 'schedule' => '0 * * * *',
            'alert_rules' => ['availability_changes' => true, 'price_drop_percent' => 5, 'cooldown_minutes' => 60]]);
        $snap = fn (int $price, string $avail, int $ago) => PriceSnapshot::query()->create(['tracked_product_id' => $p->id, 'site_domain' => 's.example.com',
            'url_hash' => 'h', 'price_minor' => $price, 'total_minor' => $price, 'currency' => 'USD', 'availability' => $avail, 'observed_at' => now()->subMinutes($ago)]);

        $snap(10000, 'out_of_stock', 120);
        $evaluator = app(AlertEvaluator::class);
        $first = $evaluator->evaluate($tenant, $m, [$snap(9000, 'in_stock', 60)]);
        $second = $evaluator->evaluate($tenant, $m, [$snap(8000, 'in_stock', 0)]);

        expect(collect($first)->pluck('type')->sort()->values()->all())->toBe(['back_in_stock', 'price_drop'])
            ->and($second)->toBe([]); // price_drop in cooldown
    });
});

it('accepts Money-shaped prices and ignores tracked_product_id on monitor PATCH', function () {
    [, $key] = tenantWithKey();

    $p = $this->postJson('/api/v1/tracked-products', ['title' => 'X', 'reference_price' => ['amount' => '329.99', 'currency' => 'EUR']], bearer($key))
        ->assertCreated()->assertJsonPath('data.reference_price', ['amount' => '329.99', 'currency' => 'EUR'])->json('data');
    $m = $this->postJson('/api/v1/monitors', ['tracked_product_id' => $p['id'], 'schedule' => '0 */6 * * *'], bearer($key))->json('data');

    $this->patchJson("/api/v1/monitors/{$m['id']}", ['tracked_product_id' => $p['id'], 'status' => 'paused'], bearer($key))
        ->assertOk()->assertJsonPath('data.status', 'paused');
    $this->getJson('/api/v1/tracked-products', bearer($key))->assertJsonPath('data.0.latest_prices', []);
});
