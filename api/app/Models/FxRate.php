<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date
 * @property string $base
 * @property string $quote
 * @property float $rate
 */
class FxRate extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'rate' => 'float'];
    }
}
