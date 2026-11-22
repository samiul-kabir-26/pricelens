<?php

namespace App\Scraping\Matching;

use App\Scraping\Planning\QueryNormalizer;

/**
 * Token-set ratio on normalized tokens, with a bonus/penalty for numeric/unit token agreement.
 */
class TitleSimilarity
{
    public function __construct(private QueryNormalizer $normalizer) {}

    /** @param array<int, string> $queryTokens */
    public function score(array $queryTokens, ?string $offerTitle): float
    {
        $offerTokens = $this->normalizer->tokens($offerTitle);
        if ($queryTokens === [] || $offerTokens === []) {
            return 0.0;
        }

        $ratio = self::tokenSetRatio($queryTokens, $offerTokens);

        $qNum = array_values(array_filter($queryTokens, fn (string $t) => preg_match('/\d/', $t) === 1));
        $oNum = array_values(array_filter($offerTokens, fn (string $t) => preg_match('/\d/', $t) === 1));
        $adjust = 0.0;
        if ($qNum !== []) {
            $present = count(array_intersect($qNum, $oNum)) / count($qNum);
            $adjust += 0.1 * $present;
            if ($this->conflictingUnits($qNum, $oNum)) {
                $adjust -= 0.2;
            }
        }

        return max(0.0, min(1.0, $ratio + $adjust));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function tokenSetRatio(array $a, array $b): float
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        $inter = array_values(array_intersect($a, $b));
        sort($inter);
        $diffA = array_values(array_diff($a, $b));
        sort($diffA);
        $diffB = array_values(array_diff($b, $a));
        sort($diffB);

        $t0 = implode(' ', $inter);
        $t1 = trim($t0.' '.implode(' ', $diffA));
        $t2 = trim($t0.' '.implode(' ', $diffB));

        $ratios = [self::ratio($t1, $t2)];
        if ($t0 !== '') {
            $ratios[] = self::ratio($t0, $t1);
            $ratios[] = self::ratio($t0, $t2);
        }
        // Coverage of query tokens guards against short offer titles scoring 1.0 on a subset.
        $coverage = count($inter) / max(1, count($a));

        return min(max($ratios), 0.15 + 0.85 * $coverage);
    }

    public static function ratio(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }
        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * Same unit, different value (e.g. 128gb vs 256gb).
     *
     * @param  array<int, string>  $q
     * @param  array<int, string>  $o
     */
    private function conflictingUnits(array $q, array $o): bool
    {
        $byUnit = function (array $tokens) {
            $map = [];
            foreach ($tokens as $t) {
                if (preg_match('/^(\d+(?:\.\d+)?)(tb|gb|mb|mm|cm|inch|hz|w|mah|ml|l|oz|kg|g|lb|pack|pcs|ct)$/', $t, $m)) {
                    $map[$m[2]][] = $m[1];
                }
            }

            return $map;
        };
        $qu = $byUnit($q);
        $ou = $byUnit($o);
        foreach ($qu as $unit => $values) {
            if (isset($ou[$unit]) && array_intersect($values, $ou[$unit]) === []) {
                return true;
            }
        }

        return false;
    }
}
