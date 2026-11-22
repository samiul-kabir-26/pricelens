<?php

use App\Models\Source;
use Database\Seeders\GlobalAdapterSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => fakePublicDns());

it('creates a site adapter from the spec\'s flat JSON', function () {
    [, $key] = tenantWithKey();

    $res = $this->postJson('/api/v1/sources', [
        'name' => 'Example Electronics', 'type' => 'site', 'domain' => 'shop.example.com', 'country' => 'US', 'currency' => 'USD',
        'enabled' => true, 'render' => 'auto',
        'rate_limit' => ['requests' => 1, 'per_seconds' => 3, 'concurrency' => 2],
        'search_url_template' => 'https://shop.example.com/search?q={query}',
        'product_url_patterns' => ['^https://shop\\.example\\.com/p/[\\w-]+'],
        'listing' => ['item' => '.product-grid .product-card', 'item_link' => 'a.product-card__link@href', 'listing_mode' => false],
        'product' => ['title' => 'h1.product-title', 'price' => '[data-testid=price-current]', 'currency' => ['static' => 'USD'], 'price_regex' => '([0-9.,]+)'],
        'headers' => ['Accept-Language' => 'en-US,en;q=0.9'],
    ], bearer($key));

    $res->assertCreated()
        ->assertJsonPath('data.config.search_url_template', 'https://shop.example.com/search?q={query}')
        ->assertJsonPath('data.config.rate_limit.per_seconds', 3)
        ->assertJsonPath('data.is_global', false)
        ->assertJsonPath('data.health_status', 'healthy');
});

it('validates adapter configs', function () {
    [, $key] = tenantWithKey();

    $this->postJson('/api/v1/sources', ['name' => 'x', 'type' => 'site'], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['domain']]);
    $this->postJson('/api/v1/sources', ['name' => 'x', 'type' => 'site', 'domain' => 'a.example.com',
        'product_url_patterns' => ['(unclosed']], bearer($key))->assertStatus(422)->assertJsonStructure(['errors' => ['config.product_url_patterns.0']]);
    $this->postJson('/api/v1/sources', ['name' => 'x', 'type' => 'site', 'domain' => 'a.example.com',
        'search_url_template' => 'https://a.example.com/search'], bearer($key))->assertStatus(422);
    $this->postJson('/api/v1/sources', ['name' => 'x', 'type' => 'search_engine'], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['config.provider']]);
});

it('browses, opts into, and forks global adapters', function () {
    (new GlobalAdapterSeeder)->run();
    [$tenant, $key] = tenantWithKey();
    $global = Source::acrossTenants()->where('is_global', true)->first();

    $this->getJson('/api/v1/sources/global', bearer($key))->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('data.1.enabled', false); // 2 sandboxes + 6 Bangladesh shops

    $this->patchJson("/api/v1/sources/{$global->ulid}", ['enabled' => true], bearer($key))->assertOk()->assertJsonPath('data.enabled', true);
    expect(asTenant($tenant, fn () => Source::defaultForTenant($tenant->id)->pluck('id')))->toContain($global->id);

    $this->patchJson("/api/v1/sources/{$global->ulid}", ['name' => 'Hijack'], bearer($key))->assertStatus(403);
    $this->deleteJson("/api/v1/sources/{$global->ulid}", [], bearer($key))->assertStatus(404);

    $fork = $this->postJson("/api/v1/sources/{$global->ulid}/fork", [], bearer($key))->assertCreated()->json('data');
    expect($fork['is_global'])->toBeFalse()->and($fork['forked_from_id'])->toBe($global->ulid)->and($fork['config'])->toBe($global->config);
    $this->getJson('/api/v1/sources', bearer($key))->assertJsonCount(1, 'data');
});

it('dry-runs an adapter against a URL with selector hits', function () {
    [, $key] = tenantWithKey();
    Http::fake(['shop.example.com/robots.txt' => Http::response('', 404), 'shop.example.com/*' => Http::response(htmlFixture('selectors.html'))]);
    $id = $this->postJson('/api/v1/sources', [
        'name' => 'Shop', 'type' => 'site', 'domain' => 'shop.example.com', 'render' => 'never',
        'product' => ['title' => 'h1.product-title', 'price' => '[data-testid=price-current]', 'currency' => ['static' => 'USD'], 'price_regex' => '([0-9.,]+)'],
    ], bearer($key))->json('data.id');

    $res = $this->postJson("/api/v1/sources/{$id}/test", ['url' => 'https://shop.example.com/p/xm5'], bearer($key));
    $res->assertOk()
        ->assertJsonPath('data.http_status', 200)
        ->assertJsonPath('data.extraction_method', 'selector')
        ->assertJsonPath('data.fields.price', '329.99')
        ->assertJsonPath('data.field_sources.gtin', 'json_ld')
        ->assertJsonPath('data.selector_hits.price.0', 'Now $329.99')
        ->assertJsonPath('data.errors', []);
});
