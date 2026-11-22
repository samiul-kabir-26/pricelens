<?php

use App\Models\ApiKey;

it('serves health without auth', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
});

it('rejects missing and invalid keys with problem+json', function () {
    $this->getJson('/api/v1/searches')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://docs.pricelens.dev/errors/unauthenticated');
    $this->getJson('/api/v1/searches', ['Authorization' => 'Bearer pl_live_abcdefgh_'.str_repeat('x', 40)])->assertStatus(401);
});

it('rejects revoked and expired keys', function () {
    [$tenant, $key] = tenantWithKey();
    ApiKey::acrossTenants()->where('tenant_id', $tenant->id)->update(['revoked_at' => now()]);

    $this->getJson('/api/v1/searches', bearer($key))->assertStatus(401);
});

it('enforces key abilities', function () {
    [, $readOnly] = tenantWithKey(abilities: ['searches:read']);

    $this->getJson('/api/v1/searches', bearer($readOnly))->assertOk();
    $this->postJson('/api/v1/searches', ['title' => 'x'], bearer($readOnly))->assertStatus(403);
    $this->getJson('/api/v1/api-keys', bearer($readOnly))->assertStatus(403);
});

it('blocks suspended tenants', function () {
    [$tenant, $key] = tenantWithKey();
    $tenant->update(['status' => 'suspended']);

    $this->getJson('/api/v1/searches', bearer($key))->assertStatus(403);
});

it('manages API keys and shows the secret once', function () {
    [, $key] = tenantWithKey();

    $created = $this->postJson('/api/v1/api-keys', ['name' => 'ci', 'abilities' => ['searches:read']], bearer($key))
        ->assertCreated()->json('data');
    expect($created['key'])->toStartWith('pl_live_');

    $this->getJson('/api/v1/searches', bearer($created['key']))->assertOk();
    $this->getJson('/api/v1/api-keys', bearer($key))->assertOk()->assertJsonMissingPath('data.0.key');

    $this->deleteJson("/api/v1/api-keys/{$created['id']}", [], bearer($key))->assertNoContent();
    $this->getJson('/api/v1/searches', bearer($created['key']))->assertStatus(401);
});

it('rate limits per tenant (plan api_rpm), not per IP', function () {
    [, $keyA] = tenantWithKey(settings: ['limits' => ['api_rpm' => 3]]);
    [, $keyB] = tenantWithKey();

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/searches', bearer($keyA))->assertOk()->assertHeader('X-RateLimit-Limit', 3);
    }
    $this->getJson('/api/v1/searches', bearer($keyA))->assertStatus(429)->assertHeader('Retry-After');

    // Same client IP, different tenant: unaffected; the limit reflects that tenant's plan (pro = 600/min).
    $this->getJson('/api/v1/searches', bearer($keyB))->assertOk()->assertHeader('X-RateLimit-Limit', 600);
});
