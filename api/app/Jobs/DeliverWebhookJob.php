<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointDisabled;
use App\Observability\Metrics;
use App\Scraping\Fetching\FetchException;
use App\Scraping\Fetching\SafeHttpClient;
use App\Webhooks\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Signed, retried, logged delivery. Retries follow 1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h (8 attempts).
 * 2xx = delivered; 410 disables the endpoint; 50 consecutive failures auto-disable it.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 15;

    public int $tries = 1; // retries are scheduled explicitly with the webhook backoff

    public function __construct(public int $deliveryId) {}

    public function handle(SafeHttpClient $http): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::acrossTenants()->with(['endpoint' => fn ($q) => $q->withoutGlobalScopes()])->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }
        if (! $delivery->endpoint) {
            $this->deliverCallback($delivery, $http);

            return;
        }
        $endpoint = $delivery->endpoint;
        if (! $endpoint->enabled && $delivery->event !== 'ping') {
            $delivery->update(['status' => 'failed', 'next_attempt_at' => null, 'response_body_excerpt' => 'endpoint disabled']);

            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $status = null;
        $excerpt = null;

        try {
            $result = $http->request('POST', $endpoint->url, [
                'body' => $body,
                'content_type' => 'application/json',
                'timeout' => (int) config('pricelens.webhooks.timeout_seconds', 10),
                'max_redirects' => 0,
                'max_bytes' => 64 * 1024,
                'headers' => [
                    'User-Agent' => 'PriceLens-Webhooks/1.0',
                    'X-PriceLens-Event' => $delivery->event,
                    'X-PriceLens-Delivery' => $delivery->ulid,
                    'X-PriceLens-Timestamp' => (string) $timestamp,
                    'X-PriceLens-Signature' => Signer::sign($endpoint->secret, $timestamp, $body),
                ],
            ]);
            $status = $result['response']->status();
            $excerpt = mb_substr($result['response']->body(), 0, 1000);
        } catch (FetchException $e) {
            $excerpt = $e->errorCode.': '.mb_substr($e->getMessage(), 0, 900);
        }

        $attempts = $delivery->attempts + 1;
        $ok = $status !== null && $status >= 200 && $status < 300;
        Metrics::increment('pricelens_webhook_deliveries_total', ['status' => $ok ? 'delivered' : 'failed']);

        if ($ok) {
            $delivery->update(['status' => 'delivered', 'attempts' => $attempts, 'response_status' => $status,
                'response_body_excerpt' => $excerpt, 'delivered_at' => now(), 'next_attempt_at' => null]);
            WebhookEndpoint::acrossTenants()->whereKey($endpoint->id)->update(['failure_count' => 0]);

            return;
        }

        $failures = $endpoint->failure_count + 1;
        $disable = $status === 410 || $failures >= (int) config('pricelens.webhooks.disable_after_failures', 50);
        $backoff = config('pricelens.webhooks.backoff_seconds');
        $retry = ! $disable && $attempts < (int) config('pricelens.webhooks.max_attempts', 8);
        $delay = $backoff[min($attempts - 1, count($backoff) - 1)];

        $delivery->update([
            'status' => $retry ? 'pending' : 'failed',
            'attempts' => $attempts,
            'response_status' => $status,
            'response_body_excerpt' => $excerpt,
            'next_attempt_at' => $retry ? now()->addSeconds($delay) : null,
        ]);
        WebhookEndpoint::acrossTenants()->whereKey($endpoint->id)->update(['failure_count' => $failures]);

        if ($disable && $endpoint->enabled) {
            WebhookEndpoint::acrossTenants()->whereKey($endpoint->id)->update(['enabled' => false, 'disabled_at' => now()]);
            $admins = User::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $endpoint->tenant_id)->whereIn('tenant_user.role', ['owner', 'admin']))->get();
            Notification::send($admins, new WebhookEndpointDisabled($endpoint->ulid, $endpoint->url, $status === 410 ? 'HTTP 410 Gone' : "{$failures} consecutive failures"));
        }

        if ($retry) {
            self::dispatch($delivery->id)->onQueue('webhooks')->delay(now()->addSeconds($delay));
        }
    }

    /** Per-search callback_url: same signing and retry schedule, no endpoint failure accounting. */
    private function deliverCallback(WebhookDelivery $delivery, SafeHttpClient $http): void
    {
        $secret = Tenant::query()->whereKey($delivery->tenant_id)->first()?->callback_secret;
        if (! $delivery->target_url || ! $secret) {
            $delivery->update(['status' => 'failed', 'next_attempt_at' => null, 'response_body_excerpt' => 'no callback secret']);

            return;
        }

        [$status, $excerpt] = $this->post($http, $delivery->target_url, $secret, $delivery);
        $attempts = $delivery->attempts + 1;
        $ok = $status !== null && $status >= 200 && $status < 300;
        Metrics::increment('pricelens_webhook_deliveries_total', ['status' => $ok ? 'delivered' : 'failed']);

        $backoff = config('pricelens.webhooks.backoff_seconds');
        $retry = ! $ok && $status !== 410 && $attempts < (int) config('pricelens.webhooks.max_attempts', 8);
        $delay = $backoff[min($attempts - 1, count($backoff) - 1)];

        $delivery->update([
            'status' => $ok ? 'delivered' : ($retry ? 'pending' : 'failed'),
            'attempts' => $attempts,
            'response_status' => $status,
            'response_body_excerpt' => $excerpt,
            'delivered_at' => $ok ? now() : null,
            'next_attempt_at' => $retry ? now()->addSeconds($delay) : null,
        ]);

        if ($retry) {
            self::dispatch($delivery->id)->onQueue('webhooks')->delay(now()->addSeconds($delay));
        }
    }

    /** @return array{0: ?int, 1: ?string} */
    private function post(SafeHttpClient $http, string $url, string $secret, WebhookDelivery $delivery): array
    {
        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();

        try {
            $result = $http->request('POST', $url, [
                'body' => $body,
                'content_type' => 'application/json',
                'timeout' => (int) config('pricelens.webhooks.timeout_seconds', 10),
                'max_redirects' => 0,
                'max_bytes' => 64 * 1024,
                'headers' => [
                    'User-Agent' => 'PriceLens-Webhooks/1.0',
                    'X-PriceLens-Event' => $delivery->event,
                    'X-PriceLens-Delivery' => $delivery->ulid,
                    'X-PriceLens-Timestamp' => (string) $timestamp,
                    'X-PriceLens-Signature' => Signer::sign($secret, $timestamp, $body),
                ],
            ]);

            return [$result['response']->status(), mb_substr($result['response']->body(), 0, 1000)];
        } catch (FetchException $e) {
            return [null, $e->errorCode.': '.mb_substr($e->getMessage(), 0, 900)];
        }
    }
}
