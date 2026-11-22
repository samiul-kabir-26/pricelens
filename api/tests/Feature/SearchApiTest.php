<?php

use App\Jobs\PlanSearchJob;
use App\Models\Plan;
use App\Models\Search;
use App\Scraping\Data\ProductQuery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakePublicDns();
    Queue::fake();
});

it('creates a search and queues planning', function () {
    [, $key] = tenantWithKey();

    $res = $this->postJson('/api/v1/searches', [
        'title' => 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black',
        'gtin' => '0027242923782',
        'sources' => ['domains' => ['shop.example.com']],
        'options' => ['target_currency' => 'USD', 'max_results' => 50],
    ], bearer($key));

    $res->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.progress', ['total' => 0, 'done' => 0])
        ->assertJsonStructure(['data' => ['id', 'links' => ['self', 'offers'], 'realtime' => ['channel']]]);

    $id = $res->json('data.id');
    expect($res->json('data.realtime.channel'))->toStartWith('private-tenant.')->toEndWith(".search.{$id}");
    Queue::assertPushedOn('orchestration', PlanSearchJob::class);
});

it('validates input with problem+json field errors', function () {
    [, $key] = tenantWithKey();

    $this->postJson('/api/v1/searches', ['options' => ['max_results' => 10]], bearer($key))
        ->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)->assertJsonStructure(['errors' => ['title']]);

    $this->postJson('/api/v1/searches', ['gtin' => '4006381333932'], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['gtin']]);

    $this->postJson('/api/v1/searches', ['title' => 'x', 'image_url' => 'http://169.254.169.254/latest'], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['image_url']]);

    $this->postJson('/api/v1/searches', ['title' => 'x', 'options' => ['max_results' => 10000]], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['options.max_results']]);
});

it('drops blocklisted domains', function () {
    [, $key] = tenantWithKey(settings: ['domains' => ['blocklist' => ['blocked.example.com']]]);

    $id = $this->postJson('/api/v1/searches', ['title' => 'x', 'sources' => ['domains' => ['blocked.example.com', 'ok.example.com']]], bearer($key))
        ->assertStatus(202)->json('data.id');

    expect(Search::acrossTenants()->where('ulid', $id)->first()->input['sources']['domains'])->toBe(['ok.example.com']);
});

it('replays idempotent requests', function () {
    [, $key] = tenantWithKey();
    $headers = bearer($key) + ['Idempotency-Key' => 'abc-123'];

    $first = $this->postJson('/api/v1/searches', ['title' => 'Same'], $headers)->assertStatus(202);
    $second = $this->postJson('/api/v1/searches', ['title' => 'Same'], $headers)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->postJson('/api/v1/searches', ['title' => 'Different'], $headers)->assertStatus(422);
    Queue::assertPushed(PlanSearchJob::class, 1);
});

it('enforces the monthly search quota', function () {
    [$tenant, $key] = tenantWithKey('starter');
    Plan::query()->where('code', 'starter')->update(['limits->searches_per_month' => 1]);

    $this->postJson('/api/v1/searches', ['title' => 'one'], bearer($key))->assertStatus(202);
    $this->postJson('/api/v1/searches', ['title' => 'two'], bearer($key))
        ->assertStatus(429)->assertJsonPath('type', 'https://docs.pricelens.dev/errors/quota_exceeded');
});

it('requires the image-search feature for reverse image', function () {
    [, $key] = tenantWithKey('starter');

    $this->postJson('/api/v1/searches', ['title' => 'x', 'sources' => ['reverse_image' => true]], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['sources.reverse_image']]);
});

it('lists, filters and cancels searches', function () {
    [, $key] = tenantWithKey();
    $id = $this->postJson('/api/v1/searches', ['title' => 'x'], bearer($key))->json('data.id');

    $this->getJson('/api/v1/searches?status=queued', bearer($key))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    $this->postJson("/api/v1/searches/{$id}/cancel", [], bearer($key))->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('serves the input image through a short-lived signed URL', function () {
    [$tenant, $key] = tenantWithKey();
    $search = asTenant($tenant, fn () => Search::query()->create(['status' => 'completed', 'input' => ['title' => 'x'], 'options' => [],
        'target_currency' => 'USD', 'country' => 'US', 'product_query' => (new ProductQuery(title: 'x',
            imagePath: "tenants/{$tenant->id}/searches/x/input.webp"))->toArray()]));
    Storage::disk('local')->put("tenants/{$tenant->id}/searches/x/input.webp", 'RIFFxxxxWEBP');

    $this->getJson("/api/v1/searches/{$search->ulid}", bearer($key))->assertJsonPath('data.links.image', "/api/v1/searches/{$search->ulid}/image");
    $location = $this->get("/api/v1/searches/{$search->ulid}/image", bearer($key))->assertRedirect()->headers->get('Location');

    expect($location)->toContain('signature=')->toContain('expires=');
    $this->get($location)->assertOk();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $location))->assertForbidden();
});
