<?php

namespace App\Http\Resources;

use App\Models\Search;
use App\Services\SearchProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Search */
class SearchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $input = $this->input;
        unset($input['image_upload_path']);
        $query = $this->product_query;
        if (is_array($query)) {
            unset($query['image_path'], $query['visual_matches']);
            $query['attributes'] = (object) ($query['attributes'] ?? []); // always a JSON object
        }

        return [
            'id' => $this->ulid,
            'status' => $this->status,
            'input' => $input,
            'product_query' => $query,
            'options' => (object) ($this->options ?? []),
            'target_currency' => $this->target_currency,
            'country' => $this->country,
            'progress' => ['total' => $this->progress_total, 'done' => min($this->progress_done, $this->progress_total)],
            'stats' => $this->stats,
            'error' => $this->error,
            'sources' => app(SearchProgress::class)->sources($this->resource),
            'monitor_id' => $this->monitor_id ? $this->monitor?->ulid : null,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'links' => array_filter([
                'self' => "/api/v1/searches/{$this->ulid}",
                'offers' => "/api/v1/searches/{$this->ulid}/offers",
                'image' => ! empty($this->product_query['image_path']) ? "/api/v1/searches/{$this->ulid}/image" : null,
            ]),
            'realtime' => ['channel' => 'private-'.$this->channelName()],
        ];
    }
}
