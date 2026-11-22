<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $enabled
 * @property int $failure_count
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const EVENTS = [
        'search.started', 'search.offer_found', 'search.completed', 'search.failed', 'monitor.run_completed',
        'alert.price_drop', 'alert.price_increase', 'alert.map_violation', 'alert.back_in_stock',
        'alert.out_of_stock', 'alert.new_seller', 'source.degraded', 'quota.threshold', 'ping',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function subscribedTo(string $event): bool
    {
        // `search.offer_found` is high volume and must be subscribed explicitly.
        if ($event === 'ping') {
            return true;
        }

        $events = $this->events ?? [];

        return in_array($event, $events, true) || (in_array('*', $events, true) && $event !== 'search.offer_found');
    }
}
