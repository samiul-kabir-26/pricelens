<?php

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Scraping\Fetching\SafeUrlGuard;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Create a tenant on a plan with an API key.
 *
 * @param  array<int, string>  $abilities
 * @param  array<string, mixed>  $settings
 * @return array{0: Tenant, 1: string}
 */
function tenantWithKey(string $plan = 'pro', array $abilities = ['admin'], array $settings = []): array
{
    if (! Plan::query()->exists()) {
        (new PlanSeeder)->run();
    }

    $tenant = Tenant::query()->create([
        'name' => 'Tenant '.Str::random(4),
        'slug' => 't-'.Str::lower(Str::random(8)),
        'plan_id' => Plan::query()->where('code', $plan)->value('id'),
        'default_country' => 'US',
        'default_currency' => 'USD',
        'settings' => $settings,
    ]);

    [, $key] = ApiKey::generate($tenant, 'test', $abilities);

    return [$tenant->load('plan'), $key];
}

/** Run a callback as the tenant (for direct model/service calls in tests). */
function asTenant(Tenant $tenant, callable $callback): mixed
{
    return CurrentTenant::instance()->runAs($tenant, $callback);
}

/** Resolve every host to a public documentation IP so no real DNS is used. */
function fakePublicDns(string $ip = '93.184.216.34'): void
{
    app()->instance(SafeUrlGuard::class, new SafeUrlGuard(fn (string $host) => [$ip]));
}

function htmlFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/html/'.$name);
}

/** @return array<string, string> */
function bearer(string $key): array
{
    return ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];
}
