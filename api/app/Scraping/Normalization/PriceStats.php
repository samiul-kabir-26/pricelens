<?php

namespace App\Scraping\Normalization;

/**
 * Robust statistics over minor-unit prices.
 */
class PriceStats
{
    /** @param array<int, int|float> $values */
    public static function median(array $values): float
    {
        return self::percentile($values, 50);
    }

    /**
     * Linear-interpolated percentile.
     *
     * @param  array<int, int|float>  $values
     */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $idx = ($p / 100) * (count($values) - 1);
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);

        return $values[$lo] + ($values[$hi] - $values[$lo]) * ($idx - $lo);
    }

    /**
     * Flags values beyond median ± 3 × MAD (scaled MAD). The band never shrinks below 15% of the median so a
     * tight cluster of near-identical prices doesn't flag normal retail spread.
     *
     * @param  array<int|string, int>  $values
     * @return array<int|string, bool>
     */
    public static function outliers(array $values): array
    {
        if (count($values) < 4) {
            return array_map(fn () => false, $values);
        }

        $median = self::median(array_values($values));
        $mad = self::median(array_map(fn ($v) => abs($v - $median), array_values($values))) * 1.4826;
        $band = max(3 * $mad, 0.15 * $median);

        return array_map(fn ($v) => abs($v - $median) > $band, $values);
    }

    /**
     * @param  array<int, int>  $values
     * @return array{min: int, max: int, median: int, mean: int, p25: int, p75: int}|null
     */
    public static function summary(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        return [
            'min' => (int) min($values),
            'max' => (int) max($values),
            'median' => (int) round(self::median($values)),
            'mean' => (int) round(array_sum($values) / count($values)),
            'p25' => (int) round(self::percentile($values, 25)),
            'p75' => (int) round(self::percentile($values, 75)),
        ];
    }
}
