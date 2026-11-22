<?php

namespace App\Http\Resources;

use App\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Monitor */
class MonitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'tracked_product_id' => $this->trackedProduct?->ulid,
            'schedule' => $this->schedule,
            'timezone' => $this->timezone,
            'source_ids' => $this->source_ids ?? [],
            'options' => $this->options ?? (object) [],
            'alert_rules' => $this->alert_rules ?? (object) [],
            'status' => $this->status,
            'next_run_at' => $this->next_run_at?->toIso8601ZuluString(),
            'last_run_at' => $this->last_run_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
