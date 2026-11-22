<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $search_id
 * @property int|null $source_id
 * @property string $site_domain
 * @property string|null $site_name
 * @property string $url
 * @property string $url_hash
 * @property string|null $title
 * @property string|null $brand
 * @property string|null $gtin
 * @property string|null $mpn
 * @property string|null $sku
 * @property string|null $image_url
 * @property string|null $image_phash
 * @property int $price_minor
 * @property int|null $list_price_minor
 * @property int|null $shipping_minor
 * @property string $currency
 * @property int|null $converted_price_minor
 * @property int|null $converted_total_minor
 * @property Carbon|null $fx_rate_date
 * @property string $availability
 * @property string $condition
 * @property string|null $seller_name
 * @property float|null $rating
 * @property int|null $review_count
 * @property array<string, mixed>|null $variant
 * @property float $match_confidence
 * @property string $match_band
 * @property string $match_type
 * @property array<string, mixed>|null $match_breakdown
 * @property string $extraction_method
 * @property bool $price_outlier
 * @property bool $is_duplicate
 * @property string|null $snapshot_path
 * @property Carbon $observed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Search $search
 * @property-read Source|null $source
 */
class Offer extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const BANDS = ['exact', 'likely', 'possible', 'rejected'];

    public const BAND_RANK = ['exact' => 3, 'likely' => 2, 'possible' => 1, 'rejected' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'variant' => 'array',
            'match_breakdown' => 'array',
            'match_confidence' => 'float',
            'rating' => 'float',
            'price_outlier' => 'boolean',
            'is_duplicate' => 'boolean',
            'observed_at' => 'datetime',
            'fx_rate_date' => 'date',
        ];
    }

    /** @return BelongsTo<Search, $this> */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class)->withoutGlobalScopes();
    }

    public function totalMinor(): int
    {
        return $this->price_minor + ($this->shipping_minor ?? 0);
    }
}
