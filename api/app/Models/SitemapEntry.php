<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product URL from a site source's sitemaps. Not tenant-scoped: it belongs to the source (often a global adapter).
 *
 * @property int $id
 * @property int $source_id
 * @property string $url_hash
 * @property string $url
 * @property string $title
 * @property Carbon|null $lastmod
 * @property Carbon $seen_at
 */
class SitemapEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lastmod' => 'datetime', 'seen_at' => 'datetime'];
    }
}
