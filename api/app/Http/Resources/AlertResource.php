<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'monitor_id' => $this->monitor?->ulid,
            'tracked_product_id' => $this->trackedProduct?->ulid,
            'type' => $this->type,
            'site_domain' => $this->site_domain,
            'payload' => $this->payload,
            'triggered_at' => $this->triggered_at?->toIso8601ZuluString(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601ZuluString(),
        ];
    }
}
