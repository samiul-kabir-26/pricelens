<?php

namespace App\Http\Resources;

use App\Models\PriceSnapshot;
use App\Models\TrackedProduct;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin TrackedProduct */
class TrackedProductResource extends JsonResource
{
    public bool $withLatestPrices = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'external_ref' => $this->external_ref,
            'title' => $this->title,
            'brand' => $this->brand,
            'gtin' => $this->gtin,
            'mpn' => $this->mpn,
            'image_url' => $this->image_url,
            'reference_price' => Money::toArray($this->reference_price_minor, $this->currency),
            'map_price' => Money::toArray($this->map_price_minor, $this->currency),
            'currency' => $this->currency,
            'attributes' => $this->attributes,
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'latest_prices' => $this->when($this->withLatestPrices, fn () => $this->latestPrices()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function latestPrices(): array
    {
        $latestIds = PriceSnapshot::query()->where('tracked_product_id', $this->id)
            ->select(DB::raw('MAX(id) as id'))->groupBy('site_domain')->pluck('id');

        return PriceSnapshot::query()->whereIn('id', $latestIds)->orderBy('total_minor')->get()
            ->map(fn (PriceSnapshot $s) => [
                'site_domain' => $s->site_domain,
                'url' => $s->url,
                'seller' => $s->seller_name,
                'price' => Money::toArray($s->price_minor, $s->currency),
                'total' => Money::toArray($s->total_minor, $s->currency),
                'converted_total' => Money::toArray($s->converted_minor, $this->currency),
                'availability' => $s->availability,
                'observed_at' => $s->observed_at?->toIso8601ZuluString(),
            ])->all();
    }
}
