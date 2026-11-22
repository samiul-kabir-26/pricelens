<?php

use App\Jobs\RefreshFxRatesJob;
use App\Observability\Metrics;
use App\Scraping\Normalization\CurrencyConverter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('reports usage against plan limits', function () {
    fakePublicDns();
    Queue::fake();
    [, $key] = tenantWithKey('starter');
    $this->postJson('/api/v1/searches', ['title' => 'x'], bearer($key));

    $this->getJson('/api/v1/usage', bearer($key))->assertOk()
        ->assertJsonPath('data.plan.code', 'starter')
        ->assertJsonPath('data.metrics.0', ['metric' => 'searches', 'used' => 1, 'limit' => 1000])
        ->assertJsonPath('data.features.llm-extraction', false);
});

it('reports readiness', function () {
    Http::fake(['renderer.test:3100/health' => Http::response(['status' => 'ok'])]);

    $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('checks.renderer.ok', true);
});

it('exposes prometheus metrics', function () {
    Metrics::increment('pricelens_offers_total', ['match_band' => 'exact']);

    $this->get('/metrics')->assertOk()->assertSee('pricelens_offers_total{match_band="exact"} 1', false);
});

it('loads ECB rates and converts via EUR cross rates', function () {
    Http::fake(['*' => Http::response(<<<'XML'
        <gesmes:Envelope><Cube><Cube time='2026-10-30'>
        <Cube currency='USD' rate='1.0850'/><Cube currency='JPY' rate='162.50'/><Cube currency='GBP' rate='0.8400'/>
        </Cube></Cube></gesmes:Envelope>
        XML)]);
    $this->travelTo('2026-10-31');

    (new RefreshFxRatesJob)->handle();
    $fx = app(CurrencyConverter::class);

    expect($fx->convert(10000, 'USD', 'JPY'))->toBe(['minor' => 14977, 'rate_date' => '2026-10-30'])
        ->and($fx->convert(10000, 'EUR', 'GBP')['minor'])->toBe(8400)
        ->and($fx->convert(500, 'USD', 'USD'))->toBe(['minor' => 500, 'rate_date' => null])
        ->and($fx->convert(500, 'USD', 'XXX'))->toBeNull();
});
