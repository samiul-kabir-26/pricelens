<?php

namespace App\Http\Middleware;

use App\Observability\Tracing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Server span per HTTP request; its traceparent flows into queued jobs via Laravel Context. */
class TraceRequest
{
    public function __construct(private Tracing $tracing) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tracing->enabled()) {
            return $next($request);
        }

        $span = $this->tracing->start("HTTP {$request->method()}", $request->header('traceparent'), [
            'http.request.method' => $request->method(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'client.address' => $request->ip(),
            'pricelens.request_id' => Context::get('request_id'),
        ], SpanKind::KIND_SERVER);
        $scope = $span->activate();
        if ($tp = Tracing::traceparent($span)) {
            Context::add('traceparent', $tp);
        }

        try {
            $response = $next($request);
            $span->updateName("HTTP {$request->method()} ".($request->route()?->uri() ?? $request->path()));
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            $span->setAttribute('pricelens.tenant_id', Context::get('tenant_id'));
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $e) {
            Tracing::fail($span, $e);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tracing->flush();
    }
}
