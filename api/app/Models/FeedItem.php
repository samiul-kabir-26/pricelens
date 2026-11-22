<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $source_id
 * @property string $item_id
 * @property string $title
 * @property string|null $brand
 * @property string|null $gtin
 * @property string|null $mpn
 * @property string $link
 * @property string|null $image_link
 * @property string|null $price
 * @property string|null $sale_price
 * @property string|null $availability
 * @property string|null $condition
 * @property string|null $shipping
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class FeedItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}
