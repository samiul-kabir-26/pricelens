<?php

use App\Exceptions\ProblemException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TraceRequest;
use App\Support\Problem;
use App\Tenancy\MissingTenantException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'ability' => EnsureAbility::class,
            'idempotency' => Idempotency::class,
        ]);
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(TraceRequest::class);
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
        // Tenant must be resolved before throttling (per-tenant/per-key limits) and before route-model binding
        // (tenant-scoped queries). Laravel's default priority runs ThrottleRequests first.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();
        $exceptions->shouldRenderJsonWhen($isApi);
        $exceptions->dontReport([ProblemException::class]);

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ProblemException => Problem::response($e->code_, $e->title, $e->status, $e->getMessage(), $e->extra, $e->headers),
                $e instanceof ValidationException => Problem::response('validation_failed', 'The request is invalid', 422,
                    $e->getMessage(), ['errors' => $e->errors()]),
                $e instanceof AuthenticationException => Problem::response('unauthenticated', 'Authentication required', 401),
                // Cross-tenant access must be indistinguishable from a missing resource.
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException,
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => Problem::response('not_found', 'Resource not found', 404),
                $e instanceof ThrottleRequestsException => Problem::response('rate_limited', 'Too many requests', 429,
                    'Rate limit exceeded; retry after the time given in Retry-After.', [], $e->getHeaders()),
                $e instanceof MissingTenantException => Problem::response('server_error', 'Internal server error', 500),
                $e instanceof HttpExceptionInterface => Problem::response('http_'.$e->getStatusCode(), $e->getMessage() ?: 'HTTP error',
                    $e->getStatusCode(), null, [], $e->getHeaders()),
                default => config('app.debug') ? null : Problem::response('server_error', 'Internal server error', 500),
            };
        });
    })->create();
