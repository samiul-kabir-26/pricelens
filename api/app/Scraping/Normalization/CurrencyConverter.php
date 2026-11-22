<?php

namespace App\Scraping\Normalization;

use App\Models\FxRate;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Converts using daily ECB reference rates stored as EUR-based pairs; cross rates go via EUR.
 */
class CurrencyConverter
{
    /**
     * @return array{minor: int, rate_date: ?string}|null null when no rate is available
     */
    public function convert(int $minor, string $from, string $to): ?array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return ['minor' => $minor, 'rate_date' => null];
        }

        $rates = $this->latestRates();
        if ($rates === null) {
            return null;
        }

        $fromRate = $from === 'EUR' ? 1.0 : ($rates['rates'][$from] ?? null);
        $toRate = $to === 'EUR' ? 1.0 : ($rates['rates'][$to] ?? null);
        if (! $fromRate || ! $toRate) {
            return null;
        }

        $amount = $minor / (10 ** Money::fractionDigits($from));
        $converted = $amount * ($toRate / $fromRate);

        return ['minor' => (int) round($converted * (10 ** Money::fractionDigits($to))), 'rate_date' => $rates['date']];
    }

    /** @return array{date: string, rates: array<string, float>}|null */
    public function latestRates(): ?array
    {
        $date = FxRate::query()->where('base', 'EUR')
            ->where('date', '>=', now()->subDays((int) config('pricelens.fx.max_staleness_days', 7))->toDateString())
            ->max('date');

        if (! $date) {
            return null;
        }
        $date = substr((string) $date, 0, 10);

        return Cache::remember("fx:{$date}", 86400, fn () => [
            'date' => $date,
            'rates' => FxRate::query()->where('base', 'EUR')->whereDate('date', $date)->pluck('rate', 'quote')
                ->map(fn ($r) => (float) $r)->all(),
        ]);
    }
}
