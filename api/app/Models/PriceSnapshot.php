<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $tracked_product_id
 * @property string $site_domain
 * @property string $url_hash
 * @property string|null $url
 * @property string|null $seller_name
 * @property int $price_minor
 * @property int|null $total_minor
 * @property string $currency
 * @property int|null $converted_minor
 * @property string $availability
 * @property int|null $search_id
 * @property Carbon $observed_at
 * @property-read Tenant $tenant
 */
class PriceSnapshot extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }
}
