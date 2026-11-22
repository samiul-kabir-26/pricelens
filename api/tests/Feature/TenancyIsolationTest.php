<?php

use App\Jobs\Middleware\TenantAware;
use App\Models\Alert;
use App\Models\ApiKey;
use App\Models\Monitor;
use App\Models\Search;
use App\Models\Source;
use App\Models\TrackedProduct;
use App\Models\WebhookEndpoint;
use App\Tenancy\CurrentTenant;
use App\Tenancy\MissingTenantException;

/** Creates one of every tenant-owned resource for tenant A and returns their ULIDs. */
function seedTenantResources($tenant): array
{
    return asTenant($tenant, function () {
        $search = Search::query()->create(['status' => 'completed', 'input' => ['title' => 'x'], 'options' => [], 'target_currency' => 'USD', 'country' => 'US']);
        $source = Source::query()->create(['type' => 'site', 'name' => 'S', 'domain' => 'a.example.com', 'config' => []]);
        $product = TrackedProduct::query()->create(['title' => 'P', 'currency' => 'USD']);
        $monitor = Monitor::query()->create(['tracked_product_id' => $product->id, 'schedule' => '0 * * * *', 'status' => 'paused']);
        $alert = Alert::query()->create(['monitor_id' => $monitor->id, 'tracked_product_id' => $product->id, 'type' => 'price_drop', 'payload' => [], 'triggered_at' => now()]);
        $webhook = WebhookEndpoint::query()->create(['url' => 'https://h.example.com', 'secret' => 's', 'events' => ['*']]);
        $apiKey = ApiKey::query()->where('tenant_id', CurrentTenant::instance()->id())->first();

        return compact('search', 'source', 'product', 'monitor', 'alert', 'webhook', 'apiKey');
    });
}

it('returns 404 for another tenant\'s resources', function (string $method, string $path) {
    fakePublicDns();
    [$a] = tenantWithKey();
    [, $keyB] = tenantWithKey();
    $r = seedTenantResources($a);

    $url = strtr($path, [
        '{search}' => $r['search']->ulid, '{source}' => $r['source']->ulid, '{product}' => $r['product']->ulid,
        '{monitor}' => $r['monitor']->ulid, '{alert}' => $r['alert']->ulid, '{webhook}' => $r['webhook']->ulid, '{apiKey}' => $r['apiKey']->ulid,
    ]);

    $this->json($method, $url, ['url' => 'https://x.example.com', 'name' => 'n', 'title' => 't', 'schedule' => '0 * * * *', 'events' => ['*']], bearer($keyB))
        ->assertStatus(404);
})->with([
    ['GET', '/api/v1/searches/{search}'],
    ['GET', '/api/v1/searches/{search}/offers'],
    ['GET', '/api/v1/searches/{search}/tasks'],
    ['GET', '/api/v1/searches/{search}/export'],
    ['POST', '/api/v1/searches/{search}/cancel'],
    ['POST', '/api/v1/searches/{search}/rerun'],
    ['GET', '/api/v1/sources/{source}'],
    ['PATCH', '/api/v1/sources/{source}'],
    ['DELETE', '/api/v1/sources/{source}'],
    ['POST', '/api/v1/sources/{source}/fork'],
    ['POST', '/api/v1/sources/{source}/test'],
    ['GET', '/api/v1/tracked-products/{product}'],
    ['PATCH', '/api/v1/tracked-products/{product}'],
    ['DELETE', '/api/v1/tracked-products/{product}'],
    ['GET', '/api/v1/tracked-products/{product}/price-history'],
    ['GET', '/api/v1/monitors/{monitor}'],
    ['PATCH', '/api/v1/monitors/{monitor}'],
    ['DELETE', '/api/v1/monitors/{monitor}'],
    ['POST', '/api/v1/monitors/{monitor}/run'],
    ['POST', '/api/v1/alerts/{alert}/acknowledge'],
    ['GET', '/api/v1/webhooks/{webhook}'],
    ['PATCH', '/api/v1/webhooks/{webhook}'],
    ['DELETE', '/api/v1/webhooks/{webhook}'],
    ['POST', '/api/v1/webhooks/{webhook}/test'],
    ['GET', '/api/v1/webhooks/{webhook}/deliveries'],
    ['DELETE', '/api/v1/api-keys/{apiKey}'],
]);

it('scopes list endpoints to the current tenant', function () {
    [$a] = tenantWithKey();
    [, $keyB] = tenantWithKey();
    seedTenantResources($a);

    foreach (['searches', 'sources', 'tracked-products', 'monitors', 'alerts', 'webhooks'] as $path) {
        $this->getJson("/api/v1/{$path}", bearer($keyB))->assertOk()->assertJsonCount(0, 'data');
    }
});

it('throws when querying tenant data without a tenant', function () {
    [$a] = tenantWithKey();
    seedTenantResources($a);

    expect(fn () => Search::query()->count())->toThrow(MissingTenantException::class);
});

it('binds and clears the tenant around jobs', function () {
    [$tenant] = tenantWithKey();
    $job = new class($tenant->id)
    {
        public ?int $seen = null;

        public function __construct(public int $tenantId) {}
    };

    (new TenantAware)->handle($job, function ($j) {
        $j->seen = CurrentTenant::instance()->id();
    });

    expect($job->seen)->toBe($tenant->id)->and(CurrentTenant::instance()->check())->toBeFalse();
});

it('rejects broadcast auth for another tenant\'s channel', function () {
    [$a, $keyA] = tenantWithKey();
    [, $keyB] = tenantWithKey();
    $search = seedTenantResources($a)['search'];
    $channel = 'private-'.$search->channelName();

    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel], bearer($keyA))
        ->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel], bearer($keyB))
        ->assertStatus(403);
});
