<?php

use App\Observability\Tracing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

it('exports request and job spans with tenant attributes and propagates traceparent to the renderer', function () {
    $exporter = new InMemoryExporter;
    app(Tracing::class)->useExporter($exporter);
    fakePublicDns();
    [$tenant, $key] = tenantWithKey();

    Http::fake([
        'spa.example.com/robots.txt' => Http::response('', 404),
        'spa.example.com/*' => Http::response(htmlFixture('spa-shell.html')),
        'renderer.test:3100/render' => Http::response(['final_url' => 'https://spa.example.com/i', 'status' => 200, 'timing_ms' => 5, 'html' => htmlFixture('meta-only.html')]),
    ]);

    $traceId = str_repeat('a', 32);
    $this->postJson('/api/v1/searches', ['title' => 'Nintendo Switch OLED', 'reference_url' => 'https://spa.example.com/i'],
        bearer($key) + ['traceparent' => "00-{$traceId}-".str_repeat('b', 16).'-01'])->assertStatus(202);

    // The renderer call carries a traceparent from the same trace.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'renderer.test') && str_contains($r->header('traceparent')[0] ?? '', $traceId));

    // HTTP server span + job spans, all in the caller's trace, with pricelens.* attributes.
    $spans = collect($exporter->getSpans());
    expect($spans->map->getName()->all())->toContain('HTTP POST api/v1/searches', 'job PlanSearchJob', 'job FetchCandidateJob', 'job RenderCandidateJob', 'job FinalizeSearchJob')
        ->and($spans->every(fn ($s) => $s->getTraceId() === $traceId))->toBeTrue();
    $fetch = $spans->first(fn ($s) => $s->getName() === 'job FetchCandidateJob');
    expect($fetch->getAttributes()->get('pricelens.tenant_id'))->toBe($tenant->id)
        ->and($fetch->getAttributes()->get('pricelens.domain'))->toBe('spa.example.com')
        ->and($fetch->getAttributes()->get('pricelens.stage'))->toBe('fetch');
});
