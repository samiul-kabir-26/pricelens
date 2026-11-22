<?php

namespace App\Scraping\Fetching;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Compressed HTML snapshots: tenants/{id}/snapshots/{sha1(url)}/{timestamp}.html.gz
 * plus a cache pointer `tenant:{t}:html:{sha1(url)}` for reuse within the tenant's cache_ttl.
 */
class SnapshotStore
{
    public function store(Tenant $tenant, string $url, string $html, bool $rendered = false): string
    {
        $hash = sha1($url);
        $path = sprintf('%s/snapshots/%s/%s%s.html.gz', $tenant->storagePrefix(), $hash, now()->format('Ymd\THis\Z'), $rendered ? '-rendered' : '');
        Storage::disk($this->disk())->put($path, (string) gzencode($html, 6));

        Cache::put($this->cacheKey($tenant, $url), ['path' => $path, 'rendered' => $rendered], $this->ttl($tenant));

        return $path;
    }

    /** @return array{path: string, rendered: bool}|null */
    public function fresh(Tenant $tenant, string $url): ?array
    {
        $pointer = Cache::get($this->cacheKey($tenant, $url));

        if (! is_array($pointer) || ! is_string($pointer['path'] ?? null) || ! Storage::disk($this->disk())->exists($pointer['path'])) {
            return null;
        }

        return ['path' => $pointer['path'], 'rendered' => (bool) ($pointer['rendered'] ?? false)];
    }

    public function load(string $path): ?string
    {
        $raw = Storage::disk($this->disk())->get($path);
        if ($raw === null) {
            return null;
        }
        $html = @gzdecode($raw);

        return $html === false ? $raw : $html;
    }

    private function cacheKey(Tenant $tenant, string $url): string
    {
        return $tenant->cachePrefix().':html:'.sha1($url);
    }

    private function ttl(Tenant $tenant): int
    {
        return (int) $tenant->setting('cache_ttl', config('pricelens.scraper.html_cache_ttl_seconds', 1800));
    }

    private function disk(): string
    {
        return config('filesystems.snapshots_disk', config('filesystems.default'));
    }
}
