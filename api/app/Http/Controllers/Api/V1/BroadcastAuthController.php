<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Search;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pusher-protocol private-channel auth for Reverb, usable with API keys (the UI's BFF forwards here).
 * Channel: private-tenant.{tenantId}.search.{searchUlid} — must belong to the caller's tenant.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);

        if (! preg_match('/^private-tenant\.(\d+)\.search\.([0-9A-HJKMNP-TV-Z]{26})$/i', $data['channel_name'], $m)
            || (int) $m[1] !== $this->tenant()->id
            || ! Search::query()->where('ulid', strtoupper($m[2]))->exists()) {
            return Problem::response('forbidden', 'Channel access denied', 403);
        }

        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');
        $signature = hash_hmac('sha256', $data['socket_id'].':'.$data['channel_name'], $secret);

        return response()->json(['auth' => "{$key}:{$signature}"]);
    }
}
