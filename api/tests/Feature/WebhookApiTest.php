<?php

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Scraping\Fetching\SafeHttpClient;
use App\Scraping\Fetching\SafeUrlGuard;
use App\Webhooks\Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => fakePublicDns());

it('creates endpoints, reveals the secret once, and sends a signed ping', function () {
    [, $key] = tenantWithKey();
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    $created = $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['search.completed']], bearer($key))
        ->assertCreated()->json('data');
    expect($created['secret'])->toStartWith('whsec_');
    $this->getJson("/api/v1/webhooks/{$created['id']}", bearer($key))->assertJsonMissingPath('data.secret');

    $this->postJson("/api/v1/webhooks/{$created['id']}/test", [], bearer($key))->assertStatus(202)->assertJsonPath('data.status', 'delivered');

    Http::assertSent(fn (Request $r) => $r->header('X-PriceLens-Event')[0] === 'ping'
        && Signer::verify($created['secret'], $r->header('X-PriceLens-Signature')[0], (int) $r->header('X-PriceLens-Timestamp')[0], $r->body())
        && json_decode($r->body(), true)['type'] === 'ping');

    $this->getJson("/api/v1/webhooks/{$created['id']}/deliveries", bearer($key))->assertJsonCount(1, 'data')->assertJsonPath('data.0.response_status', 200);
});

it('rejects internal webhook URLs', function () {
    [, $key] = tenantWithKey();
    app()->instance(SafeUrlGuard::class, new SafeUrlGuard(fn () => ['10.0.0.8']));

    $this->postJson('/api/v1/webhooks', ['url' => 'https://internal.example.com/hook', 'events' => ['*']], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['url']]);
});

it('schedules retries with backoff, then disables on 410', function () {
    [$tenant] = tenantWithKey();
    Notification::fake();
    $endpoint = asTenant($tenant, fn () => WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/x', 'secret' => 's', 'events' => ['*']]));
    $delivery = asTenant($tenant, fn () => WebhookDelivery::query()->create(['endpoint_id' => $endpoint->id, 'event' => 'search.completed',
        'event_id' => 'evt_1', 'payload' => ['id' => 'evt_1'], 'status' => 'pending']));

    Http::fakeSequence('hooks.example.com/*')->push('boom', 500)->push('gone', 410);
    Queue::fake();

    (new DeliverWebhookJob($delivery->id))->handle(app(SafeHttpClient::class));
    $delivery->refresh();
    expect($delivery->status)->toBe('pending')->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(55);
    Queue::assertPushed(DeliverWebhookJob::class);

    (new DeliverWebhookJob($delivery->id))->handle(app(SafeHttpClient::class));
    expect($delivery->refresh()->status)->toBe('failed')
        ->and(WebhookEndpoint::acrossTenants()->find($endpoint->id)->enabled)->toBeFalse();
});

it('redelivers a past delivery', function () {
    [$tenant, $key] = tenantWithKey();
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $endpoint = $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['*']], bearer($key))->json('data');
    $first = $this->postJson("/api/v1/webhooks/{$endpoint['id']}/test", [], bearer($key))->json('data');

    $this->postJson("/api/v1/webhooks/{$endpoint['id']}/deliveries/{$first['id']}/redeliver", [], bearer($key))
        ->assertStatus(202)->assertJsonPath('data.event_id', $first['event_id']);
    Http::assertSentCount(2);
});

it('delivers signed search callbacks to options.callback_url', function () {
    [$tenant, $key] = tenantWithKey();
    Http::fake([
        'shop.example.com/robots.txt' => Http::response('', 404),
        'shop.example.com/*' => Http::response(htmlFixture('jsonld-graph-aggregate.html')),
        'cb.example.com/*' => Http::response('ok'),
    ]);

    $this->postJson('/api/v1/searches', ['gtin' => '0027242923782', 'reference_url' => 'https://shop.example.com/p/x',
        'options' => ['callback_url' => 'https://cb.example.com/done']], bearer($key))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['options.callback_url']]);

    $secret = $this->postJson('/api/v1/callbacks/secret', [], bearer($key))->assertCreated()->json('data.secret');
    $this->postJson('/api/v1/searches', ['gtin' => '0027242923782', 'reference_url' => 'https://shop.example.com/p/x',
        'options' => ['callback_url' => 'https://cb.example.com/done']], bearer($key))->assertStatus(202);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://cb.example.com/done'
        && $r->header('X-PriceLens-Event')[0] === 'search.completed'
        && Signer::verify($secret, $r->header('X-PriceLens-Signature')[0], (int) $r->header('X-PriceLens-Timestamp')[0], $r->body()));
    $this->getJson('/api/v1/callbacks/deliveries', bearer($key))->assertJsonPath('data.0.status', 'delivered')
        ->assertJsonPath('data.0.target_url', 'https://cb.example.com/done');
});
