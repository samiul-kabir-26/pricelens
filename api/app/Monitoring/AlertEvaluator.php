<?php

namespace App\Monitoring;

use App\Models\Alert;
use App\Models\Monitor;
use App\Models\PriceSnapshot;
use App\Models\Tenant;
use App\Support\Money;
use App\Webhooks\WebhookDispatcher;

/**
 * Evaluates a monitor's alert rules for each new snapshot against the previous snapshot of the same
 * (tracked product, site). Per-rule/site cooldown prevents duplicates.
 *
 * Rules: price_drop_percent, price_increase_percent, target_price, map_enforcement, availability_changes,
 * new_sellers, cooldown_minutes.
 */
class AlertEvaluator
{
    public function __construct(private WebhookDispatcher $webhooks) {}

    /**
     * @param  array<int, PriceSnapshot>  $snapshots
     * @return array<int, Alert>
     */
    public function evaluate(Tenant $tenant, Monitor $monitor, array $snapshots): array
    {
        $rules = $monitor->alert_rules ?? [];
        $product = $monitor->trackedProduct;
        $alerts = [];

        foreach ($snapshots as $snap) {
            $previous = PriceSnapshot::query()
                ->where('tracked_product_id', $snap->tracked_product_id)
                ->where('site_domain', $snap->site_domain)
                ->where('observed_at', '<', $snap->observed_at)
                ->where('id', '!=', $snap->id)
                ->orderByDesc('observed_at')->first();

            // Compare in the tracked product's currency when possible.
            $price = $snap->converted_minor ?? $snap->total_minor ?? $snap->price_minor;
            $prevPrice = $previous ? ($previous->converted_minor ?? $previous->total_minor ?? $previous->price_minor) : null;
            $currency = $product->currency;

            $base = [
                'site_domain' => $snap->site_domain, 'url' => $snap->url, 'seller' => $snap->seller_name,
                'price' => Money::toArray($price, $currency),
                'previous_price' => Money::toArray($prevPrice, $currency),
                'availability' => $snap->availability,
            ];

            if ($previous === null && ($rules['new_sellers'] ?? false)) {
                $hadAny = PriceSnapshot::query()->where('tracked_product_id', $snap->tracked_product_id)->where('id', '!=', $snap->id)->exists();
                if ($hadAny) {
                    $alerts[] = $this->trigger($tenant, $monitor, 'new_seller', $snap->site_domain, $base, $rules);
                }
            }

            if ($prevPrice && $price) {
                $change = ($price - $prevPrice) / $prevPrice * 100;
                if (isset($rules['price_drop_percent']) && $change <= -abs((float) $rules['price_drop_percent'])) {
                    $alerts[] = $this->trigger($tenant, $monitor, 'price_drop', $snap->site_domain, $base + ['change_percent' => round($change, 2)], $rules);
                } elseif (isset($rules['price_increase_percent']) && $change >= abs((float) $rules['price_increase_percent'])) {
                    $alerts[] = $this->trigger($tenant, $monitor, 'price_increase', $snap->site_domain, $base + ['change_percent' => round($change, 2)], $rules);
                }
            }

            if (isset($rules['target_price']) && $price !== null) {
                $target = Money::toMinor((string) $rules['target_price'], $currency);
                $wasAbove = $prevPrice === null || $prevPrice > $target;
                if ($price <= $target && $wasAbove) {
                    $alerts[] = $this->trigger($tenant, $monitor, 'price_drop', $snap->site_domain, $base + ['target_price' => Money::toArray($target, $currency)], $rules, 'target');
                }
            }

            if (($rules['map_enforcement'] ?? false) && $product->map_price_minor && $price !== null && $price < $product->map_price_minor) {
                $alerts[] = $this->trigger($tenant, $monitor, 'map_violation', $snap->site_domain, $base + ['map_price' => Money::toArray($product->map_price_minor, $currency)], $rules);
            }

            if (($rules['availability_changes'] ?? false) && $previous && $previous->availability !== $snap->availability) {
                if ($snap->availability === 'in_stock' && $previous->availability !== 'in_stock') {
                    $alerts[] = $this->trigger($tenant, $monitor, 'back_in_stock', $snap->site_domain, $base, $rules);
                } elseif ($snap->availability === 'out_of_stock' && $previous->availability !== 'out_of_stock') {
                    $alerts[] = $this->trigger($tenant, $monitor, 'out_of_stock', $snap->site_domain, $base, $rules);
                }
            }
        }

        return array_values(array_filter($alerts));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     */
    private function trigger(Tenant $tenant, Monitor $monitor, string $type, string $site, array $payload, array $rules, string $variant = ''): ?Alert
    {
        $cooldown = (int) ($rules['cooldown_minutes'] ?? 720);
        $recent = Alert::query()->where('monitor_id', $monitor->id)->where('type', $type)->where('site_domain', $site)
            ->where('triggered_at', '>=', now()->subMinutes($cooldown))
            ->when($variant !== '', fn ($q) => $q->where('payload->rule', $variant))
            ->exists();
        if ($recent) {
            return null;
        }

        $alert = Alert::query()->create([
            'monitor_id' => $monitor->id,
            'tracked_product_id' => $monitor->tracked_product_id,
            'type' => $type,
            'site_domain' => $site,
            'payload' => $payload + ($variant !== '' ? ['rule' => $variant] : []),
            'triggered_at' => now(),
        ]);

        $this->webhooks->dispatch($tenant, "alert.{$type}", [
            'alert_id' => $alert->ulid, 'monitor_id' => $monitor->ulid, 'tracked_product_id' => $monitor->trackedProduct->ulid,
            'external_ref' => $monitor->trackedProduct->external_ref,
        ] + $alert->payload);

        return $alert;
    }
}
