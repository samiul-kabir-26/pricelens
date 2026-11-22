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
 * @property int $search_id
 * @property int $tenant_id
 * @property int|null $search_source_id
 * @property int|null $source_id
 * @property string $stage
 * @property string|null $url
 * @property string|null $url_hash
 * @property string $status
 * @property int $attempts
 * @property int|null $http_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Search $search
 * @property-read SearchSource|null $searchSource
 * @property-read Source|null $source
 */
class SearchTask extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STATUSES = ['pending', 'running', 'succeeded', 'failed', 'skipped_robots', 'skipped_policy', 'blocked', 'cancelled'];

    protected $guarded = ['id'];

    /** @return BelongsTo<Search, $this> */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /** @return BelongsTo<SearchSource, $this> */
    public function searchSource(): BelongsTo
    {
        return $this->belongsTo(SearchSource::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class)->withoutGlobalScopes();
    }
}
