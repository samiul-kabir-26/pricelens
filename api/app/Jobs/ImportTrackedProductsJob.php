<?php

namespace App\Jobs;

use App\Jobs\Middleware\TenantAware;
use App\Models\TrackedProduct;
use App\Scraping\Planning\GtinValidator;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk CSV import: external_ref,title,brand,gtin,mpn,image_url,reference_price,map_price,currency.
 * Upserts by (tenant, external_ref); rows without external_ref are inserted.
 */
class ImportTrackedProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function __construct(public int $tenantId, public string $path) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(): void
    {
        $tenant = CurrentTenant::instance()->get();
        $content = (string) Storage::disk(config('filesystems.default'))->get($this->path);
        $rows = RefreshFeedJob::parseCsv($content);

        foreach ($rows as $row) {
            if (empty($row['title'])) {
                continue;
            }
            $currency = strtoupper($row['currency'] ?? '') ?: $tenant->default_currency;
            $currency = Money::isValidCurrency($currency) ? $currency : $tenant->default_currency;
            $gtin = GtinValidator::normalize($row['gtin'] ?? null);

            $attributes = [
                'title' => mb_substr($row['title'], 0, 512),
                'brand' => ($row['brand'] ?? '') ?: null,
                'gtin' => $gtin && GtinValidator::isValid($gtin) ? $gtin : null,
                'mpn' => ($row['mpn'] ?? '') ?: null,
                'image_url' => ($row['image_url'] ?? '') ?: null,
                'reference_price_minor' => is_numeric($row['reference_price'] ?? null) ? Money::toMinor($row['reference_price'], $currency) : null,
                'map_price_minor' => is_numeric($row['map_price'] ?? null) ? Money::toMinor($row['map_price'], $currency) : null,
                'currency' => $currency,
            ];

            ! empty($row['external_ref'])
                ? TrackedProduct::query()->updateOrCreate(['external_ref' => $row['external_ref']], $attributes)
                : TrackedProduct::query()->create($attributes);
        }

        Storage::disk(config('filesystems.default'))->delete($this->path);
    }
}
