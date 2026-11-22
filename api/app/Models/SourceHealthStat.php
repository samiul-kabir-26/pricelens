<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $source_id
 * @property Carbon $window_start
 * @property int $fetch_ok
 * @property int $fetch_fail
 * @property int $extract_ok
 * @property int $extract_fail
 * @property int $blocked
 * @property int|null $p50_ms
 */
class SourceHealthStat extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'source_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['window_start' => 'datetime'];
    }
}
