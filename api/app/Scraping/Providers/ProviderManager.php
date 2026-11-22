<?php

namespace App\Scraping\Providers;

use App\Billing\QuotaGuard;
use App\Billing\UsageMeter;
use App\Models\ProviderCredential;
use App\Models\Tenant;
use App\Observability\Metrics;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves provider drivers (tenant BYO keys first, platform keys second), caches results per tenant for
 * `provider_cache_ttl`, enforces the provider-call quota and meters each uncached call.
 */
class ProviderManager
{
    public const DRIVERS = ['serpapi', 'brave', 'google_vision'];

    public function __construct(private UsageMeter $usage, private QuotaGuard $quota) {}

    public function driver(Tenant $tenant, string $name): SearchProvider
    {
        $byo = ProviderCredential::acrossTenants()->where('tenant_id', $tenant->id)->where('provider', $name)->first()?->credentials;
        $config = config("pricelens.providers.{$name}", []);

        return match ($name) {
            'serpapi' => new SerpApiProvider($this->key($byo['api_key'] ?? $config['key'] ?? null, $name), $config['base_url']),
            'brave' => new BraveProvider($this->key($byo['api_key'] ?? $config['key'] ?? null, $name), $config['base_url']),
            'google_vision' => $this->vision($byo, $config),
            default => throw new ProviderException('unknown_provider', "Unknown provider '{$name}'."),
        };
    }

    public function isConfigured(Tenant $tenant, string $name): bool
    {
        try {
            $this->driver($tenant, $name);

            return true;
        } catch (ProviderException) {
            return false;
        }
    }

    public function search(Tenant $tenant, string $name, ProviderQuery $query, bool $fresh = false): ProviderResultCollection
    {
        $driver = $this->driver($tenant, $name);
        if (! $driver->capabilities()->supports($query->mode)) {
            throw new ProviderException('unsupported_mode', "Provider '{$name}' does not support '{$query->mode}'.");
        }

        $cacheKey = $tenant->cachePrefix().':prov:'.$query->cacheKey($name);
        if (! $fresh && is_array($cached = Cache::get($cacheKey))) {
            return ProviderResultCollection::fromArray($cached);
        }

        if (! $this->quota->canCallProvider($tenant)) {
            throw new ProviderException('quota_exceeded', 'Monthly provider-call quota exhausted.');
        }

        $this->usage->record($tenant, 'provider_calls', 1, 'provider', $name);
        Metrics::increment('pricelens_provider_calls_total', ['provider' => $name, 'mode' => $query->mode]);
        $result = $driver->search($query);

        Cache::put($cacheKey, $result->toArray(), (int) config('pricelens.providers.cache_ttl_seconds', 21600));

        return $result;
    }

    /**
     * BYO credentials may carry `service_account` (the JSON object) or `api_key`; the platform falls back to
     * GOOGLE_VISION_CREDENTIALS_PATH (service account) or GOOGLE_VISION_API_KEY.
     *
     * @param  array<string, mixed>|null  $byo
     * @param  array<string, mixed>  $config
     */
    private function vision(?array $byo, array $config): GoogleVisionProvider
    {
        if (is_array($byo['service_account'] ?? null)) {
            return new GoogleVisionProvider(null, $config['base_url'], $byo['service_account']);
        }
        if (! empty($byo['api_key'])) {
            return new GoogleVisionProvider($byo['api_key'], $config['base_url']);
        }
        if ($sa = GoogleServiceAccountToken::load($config['credentials_path'] ?? null)) {
            return new GoogleVisionProvider(null, $config['base_url'], $sa);
        }

        return new GoogleVisionProvider($this->key($config['api_key'] ?? null, 'google_vision'), $config['base_url']);
    }

    private function key(?string $key, string $name): string
    {
        if (! $key) {
            throw new ProviderException('provider_not_configured', "Provider '{$name}' has no API key configured.");
        }

        return $key;
    }
}
