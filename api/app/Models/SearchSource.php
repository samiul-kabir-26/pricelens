<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One planned source within a search. Accessed through its (tenant-scoped) Search.
 *
 * @property int $id
 * @property int $search_id
 * @property int|null $source_id
 * @property string $key
 * @property string $type
 * @property string $label
 * @property string|null $domain
 * @property array<string, mixed>|null $plan
 * @property string $status
 * @property int $candidates_found
 * @property int $offers_found
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Search $search
 * @property-read Source|null $source
 */
class SearchSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan' => 'array'];
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
}
