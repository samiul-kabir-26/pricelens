<?php

namespace App\Scraping\Fetching;

use App\Support\Url;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP client that validates every hop with SafeUrlGuard, pins the resolved IP (CURLOPT_RESOLVE),
 * follows redirects manually (re-checking each), and caps the body size.
 */
class SafeHttpClient
{
    public function __construct(private SafeUrlGuard $guard) {}

    /**
     * @param  array{headers?: array<string, string>, json?: array<mixed>, body?: string, timeout?: int,
     *               connect_timeout?: int, max_bytes?: int, proxy?: string|null, max_redirects?: int,
     *               content_type?: string}  $options
     * @return array{response: Response, final_url: string}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $maxRedirects = $options['max_redirects'] ?? (int) config('pricelens.scraper.max_redirects', 5);
        $maxBytes = $options['max_bytes'] ?? (int) config('pricelens.scraper.max_body_bytes');
        $current = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            try {
                $resolved = $this->guard->check($current);
            } catch (UnsafeUrlException $e) {
                throw new FetchException($e->reason === 'dns_failed' ? 'dns_failed' : 'ssrf_blocked', $e->getMessage());
            }

            $guzzleOptions = [
                'allow_redirects' => false,
                'decode_content' => true,
                'connect_timeout' => $options['connect_timeout'] ?? 5,
                'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                    if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                        throw new FetchException('too_large', "Response body exceeds {$maxBytes} bytes.");
                    }
                },
            ];
            if (! empty($options['proxy'])) {
                $guzzleOptions['proxy'] = $options['proxy'];
            } else {
                $guzzleOptions['curl'] = [CURLOPT_RESOLVE => [$resolved->curlResolve()]];
            }

            $pending = Http::withOptions($guzzleOptions)
                ->timeout($options['timeout'] ?? (int) config('pricelens.scraper.timeout_seconds', 15))
                ->withHeaders($options['headers'] ?? []);

            try {
                $response = match (true) {
                    isset($options['json']) => $pending->send($method, $current, ['json' => $options['json']]),
                    isset($options['body']) => $pending->withBody($options['body'], $options['content_type'] ?? 'application/octet-stream')->send($method, $current),
                    default => $pending->send($method, $current),
                };
            } catch (FetchException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                $previous = $e->getPrevious();
                if ($previous instanceof FetchException) {
                    throw $previous;
                }
                $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
                throw new FetchException($timeout ? 'timeout' : 'connection_error', $e->getMessage(), retryable: true);
            } catch (Throwable $e) {
                $inner = $e;
                while ($inner) {
                    if ($inner instanceof FetchException) {
                        throw $inner;
                    }
                    $inner = $inner->getPrevious();
                }
                throw new FetchException('connection_error', $e->getMessage(), retryable: true);
            }

            if (strlen($response->body()) > $maxBytes) {
                throw new FetchException('too_large', "Response body exceeds {$maxBytes} bytes.", httpStatus: $response->status());
            }

            if ($response->redirect() && ($location = $response->header('Location'))) {
                $next = Url::absolutize($location, $current);
                if ($next === null) {
                    throw new FetchException('http_error', 'Invalid redirect location.', httpStatus: $response->status());
                }
                $current = $next;
                if (in_array($response->status(), [301, 302, 303], true)) {
                    $method = 'GET';
                    unset($options['json'], $options['body']);
                }

                continue;
            }

            return ['response' => $response, 'final_url' => $current];
        }

        throw new FetchException('http_error', "Too many redirects (>{$maxRedirects}).");
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{response: Response, final_url: string}
     */
    public function get(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }
}
