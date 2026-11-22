<?php

namespace App\Http\Resources;

use App\Models\Offer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Offer */
class OfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(?Request $request = null): array
    {
        $target = $this->search?->target_currency;
        $converted = $this->converted_total_minor !== null && $target
            ? Money::toArray($this->converted_total_minor, $target) + ['fx_rate_date' => $this->fx_rate_date?->toDateString()]
            : null;

        return [
            'id' => $this->ulid,
            'site' => ['domain' => $this->site_domain, 'name' => $this->site_name],
            'url' => $this->url,
            'title' => $this->title,
            'image_url' => $this->image_url,
            'price' => Money::toArray($this->price_minor, $this->currency),
            'list_price' => Money::toArray($this->list_price_minor, $this->currency),
            'shipping' => Money::toArray($this->shipping_minor, $this->currency),
            'total' => Money::toArray($this->totalMinor(), $this->currency),
            'converted_total' => $converted,
            'availability' => $this->availability,
            'condition' => $this->condition,
            'seller' => $this->seller_name,
            'brand' => $this->brand,
            'rating' => $this->rating,
            'review_count' => $this->review_count,
            'variant' => $this->variant,
            'identifiers' => ['gtin' => $this->gtin, 'mpn' => $this->mpn, 'sku' => $this->sku],
            'match' => [
                'confidence' => $this->match_confidence,
                'band' => $this->match_band,
                'type' => $this->match_type,
                'breakdown' => $this->match_breakdown,
            ],
            'extraction_method' => $this->extraction_method,
            'price_outlier' => $this->price_outlier,
            'is_duplicate' => $this->is_duplicate,
            'observed_at' => $this->observed_at?->toIso8601ZuluString(),
        ];
    }
}
