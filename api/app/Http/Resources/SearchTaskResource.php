<?php

namespace App\Http\Resources;

use App\Models\SearchTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SearchTask */
class SearchTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'source_id' => $this->source?->ulid,
            'source_name' => $this->searchSource?->label,
            'stage' => $this->stage,
            'url' => $this->url,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'http_status' => $this->http_status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
