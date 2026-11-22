<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Observability\Metrics;
use App\Scraping\Fetching\RendererClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: DB, cache/Redis, renderer, queue depth. */
    public function ready(RendererClient $renderer): JsonResponse
    {
        $checks = [];
        $checks['database'] = $this->check(fn () => DB::select('select 1'));
        $checks['cache'] = $this->check(function () {
            $token = bin2hex(random_bytes(4));
            Cache::put('ready:probe', $token, 5);
            if (Cache::get('ready:probe') !== $token) {
                throw new \RuntimeException('cache read-back failed');
            }
        });
        $checks['renderer'] = $renderer->healthy() ? ['ok' => true] : ['ok' => false, 'error' => 'unreachable'];

        $depth = [];
        foreach (['orchestration', 'discovery', 'fetch', 'render', 'extract', 'webhooks', 'default'] as $queue) {
            try {
                $depth[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $depth[$queue] = null;
            }
        }
        $checks['queues'] = ['ok' => true, 'depth' => $depth];

        // The renderer is optional for readiness (static fetching still works without it).
        $ready = $checks['database']['ok'] && $checks['cache']['ok'];

        return response()->json(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], $ready ? 200 : 503);
    }

    public function metrics(): Response
    {
        $token = config('pricelens.metrics_token');
        if ($token && ! hash_equals((string) $token, (string) request()->bearerToken())) {
            abort(401);
        }

        return response(Metrics::render(), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $fn): array
    {
        try {
            $fn();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }
    }
}
