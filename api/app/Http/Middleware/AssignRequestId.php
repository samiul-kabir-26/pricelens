<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Request-Id correlation; Context propagates it (and traceparent) into queued jobs and logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id');
        $id = is_string($id) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $id) ? $id : (string) Str::uuid();
        Context::add('request_id', $id);
        if ($traceparent = $request->header('traceparent')) {
            Context::add('traceparent', $traceparent);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
