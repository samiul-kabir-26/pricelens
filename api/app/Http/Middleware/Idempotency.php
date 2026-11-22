<?php

namespace App\Http\Middleware;

use App\Support\Problem;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` on POST: replays the first response for 24 h (same tenant, method and path).
 * A different body under the same key is rejected with 422.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $request->isMethod('POST') || ! is_string($key) || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 255) {
            return Problem::response('invalid_idempotency_key', 'Invalid Idempotency-Key', 400, 'Keys must be at most 255 characters.');
        }

        $tenant = CurrentTenant::instance()->get();
        $cacheKey = "{$tenant->cachePrefix()}:idem:".sha1($request->method().' '.$request->path().' '.$key);
        $fingerprint = sha1($request->getContent().json_encode($request->except([])));

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->block(10)) {
            return Problem::response('idempotency_conflict', 'Request in progress', 409, 'A request with this Idempotency-Key is still being processed.');
        }

        try {
            if (is_array($stored = Cache::get($cacheKey))) {
                if ($stored['fingerprint'] !== $fingerprint) {
                    return Problem::response('idempotency_mismatch', 'Idempotency-Key reused with a different request', 422);
                }

                return response($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
            }

            $request->attributes->set('idempotency_key', $key);
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => array_filter(['Content-Type' => $response->headers->get('Content-Type'), 'Location' => $response->headers->get('Location')]),
                ], now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
