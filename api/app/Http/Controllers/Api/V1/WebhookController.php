<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookEndpointRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Jobs\DeliverWebhookJob;
use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->paginate(WebhookEndpoint::query(), WebhookEndpointResource::class, $request);
    }

    public function store(WebhookEndpointRequest $request): JsonResponse
    {
        $data = $request->validated();
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::query()->create([
            'url' => $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'secret' => $secret,
            'enabled' => $data['enabled'] ?? true,
        ])->refresh();
        AuditLog::record('webhook.created', $endpoint, ['url' => $endpoint->url]);

        $resource = new WebhookEndpointResource($endpoint);
        $resource->plaintextSecret = $secret;

        return $resource->response()->setStatusCode(201);
    }

    public function show(WebhookEndpoint $webhook): WebhookEndpointResource
    {
        $this->authorize('view', $webhook);

        return new WebhookEndpointResource($webhook);
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $webhook): WebhookEndpointResource
    {
        $this->authorize('update', $webhook);
        $data = $request->validated();

        $webhook->fill(collect($data)->only(['url', 'events', 'enabled'])->all());
        if (($data['enabled'] ?? false) === true) {
            $webhook->fill(['failure_count' => 0, 'disabled_at' => null]);
        }
        $secret = null;
        if ($data['rotate_secret'] ?? false) {
            $secret = 'whsec_'.Str::random(40);
            $webhook->secret = $secret;
        }
        $webhook->save();
        AuditLog::record('webhook.updated', $webhook, ['fields' => array_keys($data)]);

        $resource = new WebhookEndpointResource($webhook);
        $resource->plaintextSecret = $secret;

        return $resource;
    }

    public function destroy(WebhookEndpoint $webhook): Response
    {
        $this->authorize('delete', $webhook);
        AuditLog::record('webhook.deleted', $webhook, ['url' => $webhook->url]);
        $webhook->delete();

        return response()->noContent();
    }

    public function test(WebhookEndpoint $webhook, WebhookDispatcher $dispatcher): JsonResponse
    {
        $this->authorize('update', $webhook);
        $deliveries = $dispatcher->dispatch($this->tenant(), 'ping', ['message' => 'PriceLens webhook test', 'endpoint_id' => $webhook->ulid], [$webhook->id]);

        return (new WebhookDeliveryResource($deliveries[0]->refresh()))->response()->setStatusCode(202);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook): AnonymousResourceCollection
    {
        $this->authorize('view', $webhook);
        $query = WebhookDelivery::query()->where('endpoint_id', $webhook->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e));

        return $this->paginate($query, WebhookDeliveryResource::class, $request);
    }

    public function redeliver(WebhookEndpoint $webhook, WebhookDelivery $delivery): JsonResponse
    {
        $this->authorize('update', $webhook);
        abort_unless($delivery->endpoint_id === $webhook->id, 404);

        $copy = WebhookDelivery::query()->create([
            'endpoint_id' => $webhook->id,
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'payload' => $delivery->payload,
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);
        DeliverWebhookJob::dispatch($copy->id)->onQueue('webhooks');

        return (new WebhookDeliveryResource($copy->refresh()))->response()->setStatusCode(202);
    }
}
