<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Problem;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from a `Bearer pl_live_...` API key (machine clients) or the Sanctum-authenticated
 * user's current tenant (dashboard). Binds CurrentTenant for the rest of the request.
 */
class ResolveTenant
{
    public function __construct(private CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $tenant = null;

        if ($token && str_starts_with($token, 'pl_live_')) {
            $key = ApiKey::findActive($token);
            if (! $key) {
                return Problem::response('unauthenticated', 'Invalid or revoked API key', 401);
            }
            $tenant = Tenant::query()->with('plan')->find($key->tenant_id);
            $request->attributes->set('api_key', $key);

            if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
                ApiKey::acrossTenants()->whereKey($key->id)->update(['last_used_at' => now()]);
            }
        } else {
            /** @var User|null $user */
            $user = auth('sanctum')->user();
            if (! $user) {
                return Problem::response('unauthenticated', 'Authentication required', 401,
                    'Send `Authorization: Bearer pl_live_...` or authenticate with a session.');
            }
            $tenant = $user->current_tenant_id
                ? $user->tenants()->with('plan')->whereKey($user->current_tenant_id)->first()
                : null;
            if (! $tenant) {
                return Problem::response('no_tenant', 'No active tenant', 403, 'The user is not a member of the selected tenant.');
            }
            $request->attributes->set('tenant_role', $tenant->pivot?->role);
        }

        if (! $tenant || ! $tenant->isActive()) {
            return Problem::response('tenant_suspended', 'Tenant suspended', 403);
        }

        $this->current->set($tenant);
        Context::add('tenant_id', $tenant->id);
        if (function_exists('\\Sentry\\configureScope')) {
            \Sentry\configureScope(fn (Scope $scope) => $scope->setTag('tenant_id', (string) $tenant->id));
        }

        try {
            return $next($request);
        } finally {
            $this->current->forget();
        }
    }
}
