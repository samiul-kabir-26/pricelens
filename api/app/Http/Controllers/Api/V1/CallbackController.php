<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Tenant-level signing secret for per-search `options.callback_url` deliveries, and their delivery log.
 */
class CallbackController extends Controller
{
    /** Create or rotate the secret; it is returned only in this response. */
    public function rotateSecret(): JsonResponse
    {
        $tenant = $this->tenant();
        $secret = 'cbsec_'.Str::random(40);
        $tenant->forceFill(['callback_secret' => $secret])->save();
        AuditLog::record('callback_secret.rotated', $tenant);

        return response()->json(['data' => ['secret' => $secret]], 201);
    }

    public function deliveries(Request $request): AnonymousResourceCollection
    {
        $query = WebhookDelivery::query()->whereNull('endpoint_id')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return $this->paginate($query, WebhookDeliveryResource::class, $request);
    }
}
