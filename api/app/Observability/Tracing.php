<?php

namespace App\Observability;

use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context as OtelContext;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * OpenTelemetry tracing over OTLP/HTTP (JSON). No-op unless OTEL_EXPORTER_OTLP_ENDPOINT is set.
 * W3C `traceparent` is carried between HTTP requests, queued jobs (via Laravel Context) and the renderer.
 */
class Tracing
{
    private ?TracerProvider $provider = null;

    private ?TracerInterface $tracer = null;

    /** Replace the OTLP exporter (tests: InMemoryExporter). Spans are exported synchronously. */
    public function useExporter(SpanExporterInterface $exporter): void
    {
        $this->provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $this->tracer = $this->provider->getTracer('pricelens');
        $this->forceEnabled = true;
    }

    private bool $forceEnabled = false;

    public function enabled(): bool
    {
        return $this->forceEnabled || (bool) config('pricelens.otel.endpoint');
    }

    public function tracer(): TracerInterface
    {
        if ($this->tracer) {
            return $this->tracer;
        }
        if (! $this->enabled()) {
            return $this->tracer = new NoopTracer;
        }

        try {
            $transport = (new OtlpHttpTransportFactory)->create(
                rtrim((string) config('pricelens.otel.endpoint'), '/').'/v1/traces', 'application/json', [], null, 5.0,
            );
            $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
                'service.name' => config('pricelens.otel.service_name'),
                'deployment.environment' => app()->environment(),
            ])));
            $this->provider = new TracerProvider(new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault()), null, $resource);
            $this->tracer = $this->provider->getTracer('pricelens');
        } catch (Throwable $e) {
            report($e);
            $this->tracer = new NoopTracer;
        }

        return $this->tracer;
    }

    /**
     * Start a span as a child of the given W3C traceparent (or a new root).
     *
     * @param  non-empty-string  $name
     * @param  array<string, string|int|float|bool|null>  $attributes
     * @param  SpanKind::KIND_*  $kind
     */
    public function start(string $name, ?string $traceparent, array $attributes = [], int $kind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        $parent = $traceparent ? TraceContextPropagator::getInstance()->extract(['traceparent' => $traceparent]) : OtelContext::getCurrent();

        return $this->tracer()->spanBuilder($name)->setParent($parent)->setSpanKind($kind)
            ->setAttributes(array_filter($attributes, fn ($v) => $v !== null))->startSpan();
    }

    /** W3C traceparent for a span, to hand to children (jobs, renderer). */
    public static function traceparent(SpanInterface $span): ?string
    {
        $ctx = $span->getContext();
        if (! $ctx->isValid()) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $ctx->getTraceId(), $ctx->getSpanId(), $ctx->isSampled() ? '01' : '00');
    }

    public static function fail(SpanInterface $span, Throwable $e): void
    {
        $span->recordException($e);
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }

    public function flush(): void
    {
        try {
            $this->provider?->forceFlush();
        } catch (Throwable) {
            // never break the request/job on telemetry export
        }
    }

    public function shutdown(): void
    {
        try {
            $this->provider?->shutdown();
        } catch (Throwable) {
        }
    }
}
