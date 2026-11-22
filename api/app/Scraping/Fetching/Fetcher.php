<?php

namespace App\Scraping\Fetching;

use App\Billing\UsageMeter;
use App\Models\Source;
use App\Models\Tenant;
use App\Observability\Metrics;
use App\Scraping\Data\FetchResult;
use App\Support\Url;

/**
 * Polite page fetching. Order: SSRF guard → domain policy → robots.txt → snapshot cache → HTTP fetch
 * (throttling is applied by the DomainThrottle job middleware before this runs) → status classification
 * → snapshot. Rendering goes through the same policy checks and then the renderer service.
 */
class Fetcher
{
    public function __construct(
        private SafeUrlGuard $guard,
        private SafeHttpClient $http,
        private DomainPolicyService $policies,
        private RobotsPolicy $robots,
        private ProxyPoolService $proxies,
        private SnapshotStore $snapshots,
        private CircuitBreaker $circuit,
        private RendererClient $renderer,
        private UsageMeter $usage,
    ) {}

    /**
     * @param  array{fresh?: bool, render?: bool, wait_for_selector?: string|null, reference?: string|null}  $options
     */
    public function fetch(Tenant $tenant, string $url, ?Source $source = null, array $options = []): FetchResult
    {
        $render = (bool) ($options['render'] ?? false);
        $host = $this->preflight($tenant, $url);
        $userAgent = $this->userAgent($tenant);
        $proxy = $this->proxies->pick($tenant, $source->country ?? $tenant->default_country);

        if (! $this->robots->allows($url, $userAgent, $proxy)) {
            throw new FetchException('skipped_robots', 'Disallowed by robots.txt.');
        }

        if (! ($options['fresh'] ?? false) && ($cached = $this->snapshots->fresh($tenant, $url)) && (! $render || $cached['rendered'])) {
            $html = $this->snapshots->load($cached['path']);
            if ($html !== null) {
                return new FetchResult($url, $url, 200, $html, fromCache: true, rendered: $cached['rendered'], snapshotPath: $cached['path']);
            }
        }

        if ($this->circuit->isOpen($host)) {
            throw new FetchException('circuit_open', "Circuit open for {$host}.");
        }

        try {
            $result = $render
                ? $this->renderPage($tenant, $url, $source, $proxy, $options)
                : $this->fetchStatic($tenant, $url, $source, $userAgent, $proxy);
        } catch (FetchException $e) {
            if ($e->retryable || $e->errorCode === 'blocked') {
                $this->circuit->recordFailure($host);
            }
            Metrics::increment('pricelens_fetch_http_status_total', ['status' => (string) ($e->httpStatus ?? $e->errorCode)]);
            throw $e;
        }

        $this->circuit->recordSuccess($host);
        Metrics::increment('pricelens_fetch_http_status_total', ['status' => (string) $result->status]);
        $result->snapshotPath = $this->snapshots->store($tenant, $url, $result->body, $result->rendered);

        return $result;
    }

    /** SSRF + policy checks shared with discovery. Returns the host. */
    public function preflight(Tenant $tenant, string $url): string
    {
        try {
            $resolved = $this->guard->check($url);
        } catch (UnsafeUrlException $e) {
            throw new FetchException($e->reason === 'dns_failed' ? 'dns_failed' : 'ssrf_blocked', $e->getMessage());
        }
        $this->policies->assertAllowed($tenant, $resolved->host);

        return $resolved->host;
    }

    public function userAgent(Tenant $tenant): string
    {
        return (string) $tenant->setting('user_agent', config('pricelens.scraper.user_agent'));
    }

    private function fetchStatic(Tenant $tenant, string $url, ?Source $source, string $userAgent, ?string $proxy): FetchResult
    {
        $started = hrtime(true);
        $headers = array_merge([
            'User-Agent' => $userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => $this->acceptLanguage($tenant),
            'Accept-Encoding' => 'gzip, deflate, br',
        ], (array) ($source?->cfg('headers') ?? []));
        // A tenant-configured header must never override the identifying User-Agent.
        $headers['User-Agent'] = $userAgent;

        $result = $this->http->get($url, ['headers' => $headers, 'proxy' => $proxy]);
        $response = $result['response'];
        $status = $response->status();
        $duration = (int) ((hrtime(true) - $started) / 1e6);

        $this->classify($status, $response->header('Retry-After'));

        return new FetchResult($url, $result['final_url'], $status, $response->body(), [
            'content-type' => $response->header('Content-Type'),
        ], $duration);
    }

    /** @param array<string, mixed> $options */
    private function renderPage(Tenant $tenant, string $url, ?Source $source, ?string $proxy, array $options): FetchResult
    {
        $this->usage->record($tenant, 'render_pages', 1, 'url', substr(sha1($url), 0, 16));

        $result = $this->renderer->render($url, [
            'wait_for_selector' => $options['wait_for_selector'] ?? $source?->cfg('wait_for_selector'),
            'proxy' => $proxy,
            'locale' => str_replace('_', '-', $tenant->default_language).'-'.$tenant->default_country,
        ]);

        // The renderer follows redirects itself; re-check the final URL so a redirect can't land on a blocked host.
        if (Url::host($result->finalUrl) !== Url::host($url)) {
            $this->preflight($tenant, $result->finalUrl);
        }
        $this->classify($result->status, null);

        return $result;
    }

    private function classify(int $status, ?string $retryAfter): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $retryAfterSeconds = $retryAfter !== null && $retryAfter !== ''
            ? (is_numeric($retryAfter) ? (int) $retryAfter : max(0, (int) (strtotime($retryAfter) - time())))
            : null;

        throw match (true) {
            in_array($status, [429, 502, 503, 504], true) => new FetchException($status === 429 ? 'rate_limited' : 'http_error', "HTTP {$status}", retryable: true, httpStatus: $status, retryAfter: $retryAfterSeconds),
            in_array($status, [401, 403], true) => new FetchException('blocked', "HTTP {$status}", httpStatus: $status),
            in_array($status, [404, 410], true) => new FetchException('not_found', "HTTP {$status}", httpStatus: $status),
            default => new FetchException('http_error', "HTTP {$status}", httpStatus: $status),
        };
    }

    private function acceptLanguage(Tenant $tenant): string
    {
        $lang = $tenant->default_language ?: 'en';
        $country = $tenant->default_country ?: 'US';

        return "{$lang}-{$country},{$lang};q=0.9,en;q=0.5";
    }
}
