<?php

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookEndpoint */
class WebhookEndpointResource extends JsonResource
{
    public ?string $plaintextSecret = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'url' => $this->url,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'failure_count' => $this->failure_count,
            'disabled_at' => $this->disabled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'secret' => $this->when($this->plaintextSecret !== null, $this->plaintextSecret),
        ];
    }
}
