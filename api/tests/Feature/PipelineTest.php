<?php

use App\Models\Offer;
use App\Models\Search;
use App\Models\Source;
use App\Models\UsageRecord;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakePublicDns();
});

function fakeShop(): void
{
    Http::fake([
        'shop.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /cart\n"),
        'shop.example.com/search*' => Http::response(htmlFixture('shop-search-results.html')),
        'shop.example.com/p/sony-wh-1000xm5-black*' => Http::response(htmlFixture('jsonld-graph-aggregate.html')),
        'shop.example.com/p/xm5-case*' => Http::response(htmlFixture('shop-case.html')),
        'hooks.example.com/*' => Http::response('ok', 200),
        '*' => Http::response('not found', 404),
    ]);
}

it('runs the full pipeline: discover → fetch → extract → match → finalize → webhooks', function () {
    [$tenant, $key] = tenantWithKey();
    fakeShop();

    $source = asTenant($tenant, fn () => Source::query()->create([
        'type' => 'site', 'name' => 'Example Electronics', 'domain' => 'shop.example.com', 'currency' => 'USD', 'render' => 'never',
        'config' => [
            'search_url_template' => 'https://shop.example.com/search?q={query}',
            'product_url_patterns' => ['^https://shop\.example\.com/p/[\w-]+'],
            'listing' => ['item' => '.product-grid .product-card', 'item_link' => 'a.product-card__link@href', 'item_title' => '.product-card__title'],
        ],
    ]));
    $endpoint = asTenant($tenant, fn () => WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.com/pricelens', 'secret' => 'whsec_test', 'events' => ['*'],
    ]));

    $id = $this->postJson('/api/v1/searches', [
        'title' => 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black',
        'gtin' => '0027242923782',
        'sources' => ['source_ids' => [$source->ulid]],
        'options' => ['target_currency' => 'USD', 'condition' => 'new'],
    ], bearer($key))->assertStatus(202)->json('data.id');

    $search = $this->getJson("/api/v1/searches/{$id}", bearer($key))->assertOk()->json('data');
    expect($search['status'])->toBe('completed')
        ->and($search['progress']['done'])->toBe($search['progress']['total'])
        ->and($search['product_query']['model'])->toBe('WH-1000XM5')
        ->and($search['sources'][0])->toMatchArray(['name' => 'Example Electronics', 'status' => 'completed', 'candidates_found' => 2, 'offers_found' => 1])
        ->and($search['stats'])->toMatchArray(['currency' => 'USD', 'min' => '329.99', 'median' => '329.99', 'offer_count' => 1, 'site_count' => 1]);

    $offers = $this->getJson("/api/v1/searches/{$id}/offers", bearer($key))->assertOk();
    $offers->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.url', 'https://shop.example.com/p/sony-wh-1000xm5-black')
        ->assertJsonPath('data.0.price', ['amount' => '329.99', 'currency' => 'USD'])
        ->assertJsonPath('data.0.list_price', ['amount' => '399.99', 'currency' => 'USD'])
        ->assertJsonPath('data.0.shipping', ['amount' => '0.00', 'currency' => 'USD'])
        ->assertJsonPath('data.0.total.amount', '329.99')
        ->assertJsonPath('data.0.availability', 'in_stock')
        ->assertJsonPath('data.0.condition', 'new')
        ->assertJsonPath('data.0.seller', 'Example Electronics')
        ->assertJsonPath('data.0.match.type', 'exact_gtin')
        ->assertJsonPath('data.0.match.band', 'exact')
        ->assertJsonPath('data.0.extraction_method', 'json_ld')
        ->assertJsonPath('meta.stats.cheapest_offer_id', $offers->json('data.0.id'));

    // The accessory is stored but hidden (rejected band).
    $this->getJson("/api/v1/searches/{$id}/offers?band=rejected", bearer($key))->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.match.breakdown.hard_negatives.0', 'accessory:case');

    $tasks = collect($this->getJson("/api/v1/searches/{$id}/tasks", bearer($key))->assertOk()->json('data'));
    expect($tasks->pluck('stage')->countBy()->all())->toBe(['discover' => 1, 'fetch' => 2, 'extract' => 2]);

    // Webhooks: search.started + search.completed, signed.
    $events = WebhookDelivery::acrossTenants()->where('endpoint_id', $endpoint->id)->pluck('status', 'event')->all();
    expect($events)->toMatchArray(['search.started' => 'delivered', 'search.completed' => 'delivered'])
        ->and($events)->not->toHaveKey('search.offer_found'); // high volume: explicit opt-in only

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'hooks.example.com') || $request->header('X-PriceLens-Event')[0] !== 'search.completed') {
            return false;
        }

        return Signer::verify('whsec_test', $request->header('X-PriceLens-Signature')[0], (int) $request->header('X-PriceLens-Timestamp')[0], $request->body());
    });
});

it('honors robots.txt', function () {
    [$tenant, $key] = tenantWithKey();
    Http::fake([
        'shop.example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /p/\n"),
        'shop.example.com/*' => Http::response(htmlFixture('jsonld-graph-aggregate.html')),
    ]);

    $id = $this->postJson('/api/v1/searches', ['reference_url' => 'https://shop.example.com/p/sony-wh-1000xm5-black'], bearer($key))->json('data.id');

    $tasks = collect($this->getJson("/api/v1/searches/{$id}/tasks", bearer($key))->json('data'));
    expect($tasks->firstWhere('stage', 'fetch')['status'])->toBe('skipped_robots');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/p/'));
});

it('marks 403 responses as blocked and finishes failed', function () {
    [, $key] = tenantWithKey();
    Http::fake(['shop.example.com/robots.txt' => Http::response('', 404), '*' => Http::response('denied', 403)]);

    $id = $this->postJson('/api/v1/searches', ['reference_url' => 'https://shop.example.com/p/x'], bearer($key))->json('data.id');

    $this->getJson("/api/v1/searches/{$id}", bearer($key))->assertJsonPath('data.status', 'failed');
    $task = collect($this->getJson("/api/v1/searches/{$id}/tasks", bearer($key))->json('data'))->firstWhere('stage', 'fetch');
    expect($task)->toMatchArray(['status' => 'blocked', 'http_status' => 403, 'error_code' => 'blocked']);
});

it('hands client-rendered pages to the renderer', function () {
    [, $key] = tenantWithKey();
    Http::fake([
        'spa.example.com/robots.txt' => Http::response('', 404),
        'spa.example.com/*' => Http::response(htmlFixture('spa-shell.html')),
        'renderer.test:3100/render' => Http::response([
            'final_url' => 'https://spa.example.com/item/1', 'status' => 200, 'timing_ms' => 1200,
            'html' => htmlFixture('meta-only.html'), 'screenshot_base64' => null,
        ]),
    ]);

    $id = $this->postJson('/api/v1/searches', ['title' => 'Nintendo Switch OLED White', 'reference_url' => 'https://spa.example.com/item/1'], bearer($key))->json('data.id');

    $this->getJson("/api/v1/searches/{$id}/offers", bearer($key))->assertJsonCount(1, 'data')->assertJsonPath('data.0.extraction_method', 'meta');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'renderer.test') && $r->header('Authorization')[0] === 'Bearer test-token');
    expect(collect($this->getJson("/api/v1/searches/{$id}/tasks", bearer($key))->json('data'))->pluck('stage'))->toContain('render');
});

it('turns shopping-engine results into offers with currency conversion', function () {
    [$tenant, $key] = tenantWithKey();
    DB::table('fx_rates')->insert([
        ['date' => now()->toDateString(), 'base' => 'EUR', 'quote' => 'USD', 'rate' => 1.10],
        ['date' => now()->toDateString(), 'base' => 'EUR', 'quote' => 'GBP', 'rate' => 0.85],
    ]);
    Http::fake([
        'serpapi.com/*' => Http::response(['shopping_results' => [
            ['title' => 'Sony WH-1000XM5 Black', 'link' => 'https://uk-shop.example.co.uk/xm5', 'price' => '£279.00', 'source' => 'UK Shop'],
        ]]),
    ]);

    $id = $this->postJson('/api/v1/searches', [
        'title' => 'Sony WH-1000XM5 Black',
        'sources' => ['search_engines' => [['provider' => 'serpapi', 'mode' => 'shopping']]],
        'options' => ['target_currency' => 'USD'],
    ], bearer($key))->json('data.id');

    $offer = $this->getJson("/api/v1/searches/{$id}/offers", bearer($key))->assertJsonCount(1, 'data')->json('data.0');
    // 279 GBP → EUR (/0.85) → USD (*1.10) = 361.06
    expect($offer['price'])->toBe(['amount' => '279.00', 'currency' => 'GBP'])
        ->and($offer['converted_total']['amount'])->toBe('361.06')
        ->and($offer['extraction_method'])->toBe('provider')
        ->and($offer['site']['name'])->toBe('UK Shop');
    expect(UsageRecord::acrossTenants()->where('tenant_id', $tenant->id)->where('metric', 'provider_calls')->count())->toBe(1);
});

it('exports offers as CSV with formula injection neutralized', function () {
    [$tenant, $key] = tenantWithKey();
    fakeShop();

    $id = $this->postJson('/api/v1/searches', ['gtin' => '0027242923782', 'reference_url' => 'https://shop.example.com/p/sony-wh-1000xm5-black'], bearer($key))->json('data.id');
    Offer::acrossTenants()->update(['title' => '=HYPERLINK("http://evil")']);

    $csv = $this->get("/api/v1/searches/{$id}/export?format=csv", bearer($key))->assertOk()->streamedContent();

    expect($csv)->toContain('offer_id,site_domain')->toContain("'=HYPERLINK")->toContain('329.99');
});
