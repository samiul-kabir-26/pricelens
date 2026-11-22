<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * tenant_id NULL = platform pool. `proxies` is an encrypted list of proxy URLs (http://user:pass@host:port).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property array<int, string> $proxies
 * @property string|null $country
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProxyPool extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['proxies'];

    protected function casts(): array
    {
        return ['proxies' => 'encrypted:array'];
    }
}
