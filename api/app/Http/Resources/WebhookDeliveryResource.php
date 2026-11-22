<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookDelivery */
class WebhookDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'event' => $this->event,
            'target_url' => $this->when($this->endpoint_id === null, $this->target_url),
            'event_id' => $this->event_id,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'response_status' => $this->response_status,
            'response_body_excerpt' => $this->response_body_excerpt,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'payload' => $this->when($request->boolean('include_payload'), $this->payload),
        ];
    }
}
