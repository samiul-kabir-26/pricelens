<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ability:searches:write` — API keys must hold the ability; dashboard users are mapped by role.
 */
class EnsureAbility
{
    private const ROLE_ABILITIES = [
        'owner' => ['*'],
        'admin' => ['*'],
        'member' => ['searches:read', 'searches:write', 'sources:write', 'monitors:write'],
        'viewer' => ['searches:read'],
    ];

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var ApiKey|null $key */
        $key = $request->attributes->get('api_key');

        $allowed = $key
            ? $key->allows($ability)
            : (function () use ($request, $ability) {
                $abilities = self::ROLE_ABILITIES[(string) $request->attributes->get('tenant_role')] ?? [];

                return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
            })();

        if (! $allowed) {
            return Problem::response('forbidden', 'Missing ability', 403, "This action requires the '{$ability}' ability.");
        }

        return $next($request);
    }
}
