<?php

namespace App\Http\Resources;

use App\Models\Source;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Source */
class SourceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tenantId = CurrentTenant::instance()->check() ? CurrentTenant::instance()->id() : null;

        return [
            'id' => $this->ulid,
            'type' => $this->type,
            'name' => $this->name,
            'domain' => $this->domain,
            'country' => $this->country,
            'currency' => $this->currency,
            'render' => $this->render,
            'enabled' => $tenantId ? $this->isEnabledForTenant($tenantId) : $this->enabled,
            'is_global' => $this->is_global,
            'health_status' => $this->health_status,
            'config' => $this->config,
            'forked_from_id' => $this->forked_from_id ? Source::acrossTenants()->whereKey($this->forked_from_id)->value('ulid') : null,
            'feed_refreshed_at' => $this->feed_refreshed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
