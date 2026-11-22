<?php

namespace App\Scraping\Fetching;

use Illuminate\Support\Facades\Cache;
use Spatie\Robots\RobotsTxt;
use Throwable;

/**
 * robots.txt, cached 24h per host. 4xx = allow all; 5xx/unreachable = disallow (cached 1h).
 */
class RobotsPolicy
{
    private const UNREACHABLE = "\0unreachable";

    public function __construct(private SafeHttpClient $http) {}

    public function allows(string $url, string $userAgent, ?string $proxy = null): bool
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return false;
        }
        $origin = strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));

        $content = $this->content($origin, $userAgent, $proxy);
        if ($content === self::UNREACHABLE) {
            return false;
        }
        if (trim($content) === '') {
            return true;
        }

        // Never RobotsTxt::create(): it fetches remote URLs when the content mentions "http" + "robots.txt".
        $robots = new RobotsTxt($content);
        $token = strtolower(strtok($userAgent, '/ ') ?: '*');

        return $robots->allows($url, $token) && $robots->allows($url, '*');
    }

    public function content(string $origin, string $userAgent, ?string $proxy = null): string
    {
        $key = 'robots:'.sha1($origin);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $ttl = (int) config('pricelens.scraper.robots_ttl_seconds', 86400);

        try {
            $result = $this->http->get($origin.'/robots.txt', [
                'headers' => ['User-Agent' => $userAgent, 'Accept' => 'text/plain,*/*;q=0.5'],
                'timeout' => 10,
                'max_bytes' => 512 * 1024,
                'proxy' => $proxy,
            ]);
            $status = $result['response']->status();
            if ($status >= 500) {
                Cache::put($key, self::UNREACHABLE, 3600);

                return self::UNREACHABLE;
            }
            $content = $status >= 400 ? '' : $result['response']->body();
        } catch (FetchException $e) {
            if (in_array($e->errorCode, ['ssrf_blocked', 'dns_failed'], true)) {
                throw $e;
            }
            Cache::put($key, self::UNREACHABLE, 3600);

            return self::UNREACHABLE;
        } catch (Throwable) {
            Cache::put($key, self::UNREACHABLE, 3600);

            return self::UNREACHABLE;
        }

        Cache::put($key, $content, $ttl);

        return $content;
    }
}
