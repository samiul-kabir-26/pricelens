<?php

namespace App\Scraping\Fetching;

use App\Models\ProxyPool;
use App\Models\Tenant;

/**
 * Tenant BYO pool first, platform pool second. Returns a proxy URL or null (direct).
 */
class ProxyPoolService
{
    public function pick(Tenant $tenant, ?string $country = null): ?string
    {
        foreach ([$tenant->id, null] as $owner) {
            $pools = ProxyPool::query()
                ->when($owner, fn ($q) => $q->where('tenant_id', $owner), fn ($q) => $q->whereNull('tenant_id'))
                ->get()
                ->sortByDesc(fn (ProxyPool $p) => $country && $p->country === $country ? 1 : 0);

            foreach ($pools as $pool) {
                $proxies = array_values(array_filter((array) $pool->proxies));
                if ($proxies !== []) {
                    return $proxies[array_rand($proxies)];
                }
            }
        }

        return null;
    }

    /**
     * Split a proxy URL into the renderer's { server, username, password } shape.
     *
     * @return array{server: string, username?: string, password?: string}|null
     */
    public static function forRenderer(?string $proxy): ?array
    {
        if (! $proxy || ! ($p = parse_url($proxy)) || empty($p['host'])) {
            return null;
        }

        return array_filter([
            'server' => ($p['scheme'] ?? 'http').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''),
            'username' => isset($p['user']) ? urldecode($p['user']) : null,
            'password' => isset($p['pass']) ? urldecode($p['pass']) : null,
        ]);
    }
}
