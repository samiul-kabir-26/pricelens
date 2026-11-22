<?php

namespace App\Jobs\Middleware;

use App\Observability\Tracing;
use Closure;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Span per job with tenant/search/source/domain/stage attributes, parented on the dispatching trace.
 * The job's own traceparent replaces the Context value so its children (and the renderer) nest under it.
 */
class TraceJob
{
    public function handle(object $job, Closure $next): mixed
    {
        $tracing = app(Tracing::class);
        if (! $tracing->enabled()) {
            return $next($job);
        }

        $parent = Context::get('traceparent');
        $span = $tracing->start('job '.class_basename($job), $parent, [
            'pricelens.tenant_id' => $job->tenantId ?? null,
            'pricelens.search_id' => $job->searchId ?? null,
            'pricelens.search_source_id' => $job->searchSourceId ?? null,
            'pricelens.domain' => method_exists($job, 'throttleHost') ? $job->throttleHost() : null,
            'pricelens.stage' => $this->stage($job),
            'messaging.destination.name' => $job->queue ?? null,
        ], SpanKind::KIND_CONSUMER);
        $scope = $span->activate();
        if ($tp = Tracing::traceparent($span)) {
            Context::add('traceparent', $tp);
        }

        try {
            return $next($job);
        } catch (Throwable $e) {
            Tracing::fail($span, $e);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
            $parent !== null ? Context::add('traceparent', $parent) : Context::forget('traceparent');
            $tracing->flush();
        }
    }

    private function stage(object $job): ?string
    {
        return match (class_basename($job)) {
            'PlanSearchJob' => 'plan',
            'DiscoverJob' => 'discover',
            'FetchCandidateJob' => 'fetch',
            'RenderCandidateJob' => 'render',
            'ExtractOfferJob', 'MatchOfferJob' => 'extract',
            'FinalizeSearchJob' => 'finalize',
            default => null,
        };
    }
}
