<?php

namespace App\Scraping\Fetching;

use App\Scraping\Data\FetchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * Client for the internal Node/Playwright renderer service (POST /render).
 */
class RendererClient
{
    /**
     * @param  array{wait_for_selector?: string|null, proxy?: string|null, locale?: string|null, wait_until?: string}  $options
     */
    public function render(string $url, array $options = []): FetchResult
    {
        $timeoutMs = (int) config('pricelens.renderer.timeout_ms', 20000);
        $started = hrtime(true);

        $headers = [];
        if ($traceparent = Context::get('traceparent')) {
            $headers['traceparent'] = $traceparent;
        }

        try {
            $response = Http::withToken((string) config('pricelens.renderer.token'))
                ->withHeaders($headers)
                ->timeout((int) ceil($timeoutMs / 1000) + 10)
                ->acceptJson()
                ->post(rtrim((string) config('pricelens.renderer.url'), '/').'/render', array_filter([
                    'url' => $url,
                    'wait_until' => $options['wait_until'] ?? 'networkidle',
                    'wait_for_selector' => $options['wait_for_selector'] ?? null,
                    'timeout_ms' => $timeoutMs,
                    'proxy' => ProxyPoolService::forRenderer($options['proxy'] ?? null),
                    'block_resources' => ['image', 'media', 'font'],
                    'viewport' => ['width' => 1366, 'height' => 900],
                    'locale' => $options['locale'] ?? 'en-US',
                    'screenshot' => false,
                ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw new FetchException('renderer_error', $e->getMessage(), retryable: true);
        }

        if ($response->status() === 503) {
            throw new FetchException('renderer_saturated', 'Renderer saturated.', retryable: true, retryAfter: (int) ($response->header('Retry-After') ?: 5));
        }
        if ($response->status() === 403 && $response->json('error') === 'blocked_address') {
            throw new FetchException('ssrf_blocked', 'Renderer blocked a private/internal address: '.$response->json('message'));
        }
        if ($response->status() === 504) {
            throw new FetchException('timeout', 'Renderer navigation timed out.', retryable: true);
        }
        if (! $response->successful()) {
            throw new FetchException('renderer_error', 'Renderer returned '.$response->status().': '.substr($response->body(), 0, 200), retryable: $response->serverError());
        }

        $data = $response->json();
        $status = (int) ($data['status'] ?? 0);

        return new FetchResult(
            url: $url,
            finalUrl: (string) ($data['final_url'] ?? $url),
            status: $status,
            body: (string) ($data['html'] ?? ''),
            durationMs: (int) ($data['timing_ms'] ?? (hrtime(true) - $started) / 1e6),
            rendered: true,
        );
    }

    public function healthy(): bool
    {
        try {
            return Http::timeout(2)->get(rtrim((string) config('pricelens.renderer.url'), '/').'/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
