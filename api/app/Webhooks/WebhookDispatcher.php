<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    /**
     * Fan an event out to every enabled endpoint of the tenant subscribed to it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>|null  $onlyEndpointIds
     * @return array<int, WebhookDelivery>
     */
    public function dispatch(Tenant $tenant, string $event, array $data, ?array $onlyEndpointIds = null): array
    {
        $endpoints = WebhookEndpoint::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->where('enabled', true)
            ->when($onlyEndpointIds, fn ($q) => $q->whereIn('id', $onlyEndpointIds))
            ->get()
            ->filter(fn (WebhookEndpoint $e) => $e->subscribedTo($event));

        if ($endpoints->isEmpty()) {
            return [];
        }

        $envelope = $this->envelope($tenant, $event, $data);

        $deliveries = [];
        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::acrossTenants()->create([
                'tenant_id' => $tenant->id,
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'event_id' => $envelope['id'],
                'payload' => $envelope,
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);
            DeliverWebhookJob::dispatch($delivery->id)->onQueue('webhooks');
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * One-off delivery to a search's `options.callback_url`, signed with the tenant's callback secret.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatchCallback(Tenant $tenant, string $url, string $event, array $data): ?WebhookDelivery
    {
        if (! $tenant->callback_secret) {
            return null;
        }

        $delivery = WebhookDelivery::acrossTenants()->create([
            'tenant_id' => $tenant->id,
            'endpoint_id' => null,
            'target_url' => $url,
            'event' => $event,
            'event_id' => '',
            'payload' => $envelope = $this->envelope($tenant, $event, $data),
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);
        $delivery->update(['event_id' => $envelope['id']]);
        DeliverWebhookJob::dispatch($delivery->id)->onQueue('webhooks');

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, type: string, created_at: string, tenant_id: string, data: array<string, mixed>}
     */
    private function envelope(Tenant $tenant, string $event, array $data): array
    {
        return [
            'id' => 'evt_'.Str::lower((string) Str::ulid()),
            'type' => $event,
            'created_at' => now()->utc()->toIso8601ZuluString(),
            'tenant_id' => $tenant->ulid,
            'data' => $data,
        ];
    }
}
