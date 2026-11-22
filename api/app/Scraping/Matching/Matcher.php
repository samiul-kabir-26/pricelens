<?php

namespace App\Scraping\Matching;

use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Data\MatchResult;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Normalization\ConditionMapper;
use App\Scraping\Planning\GtinValidator;
use App\Scraping\Planning\IdentifierExtractor;
use App\Scraping\Planning\QueryNormalizer;

/**
 * Explainable match confidence. GTIN equality/inequality short-circuits; otherwise a weighted score over the
 * available signals (weights re-normalized), multiplied by 0.3 per hard-negative set.
 */
class Matcher
{
    public function __construct(
        private TitleSimilarity $titles,
        private HardNegatives $hardNegatives,
        private IdentifierExtractor $identifiers,
    ) {}

    /**
     * @param  array<string, float>|null  $bands  thresholds: exact, likely, possible
     */
    public function score(ProductQuery $query, ExtractedProduct $offer, ?string $requestedCondition = null, ?string $offerImagePhash = null, ?array $bands = null): MatchResult
    {
        $bands ??= config('pricelens.matching.bands');
        $offerGtin = GtinValidator::normalize((string) $offer->get('gtin', ''));

        if ($query->gtin && $offerGtin && GtinValidator::isValid($offerGtin)) {
            if (GtinValidator::equals($query->gtin, $offerGtin)) {
                return new MatchResult(1.0, 'exact', 'exact_gtin', ['gtin' => 1.0]);
            }

            return new MatchResult(0.0, 'rejected', 'mismatch_gtin', ['gtin' => 0.0]);
        }

        $title = (string) $offer->get('title', '');
        $weights = config('pricelens.matching.weights');
        $signals = [];

        if ($query->tokens !== [] && $title !== '') {
            $signals['title'] = $this->titles->score($query->tokens, $title);
        }

        $queryModel = QueryNormalizer::normalizeModel($query->model ?? $query->mpn);
        if ($queryModel) {
            $offerModels = array_filter([
                QueryNormalizer::normalizeModel((string) $offer->get('mpn', '')),
                QueryNormalizer::normalizeModel((string) $offer->get('model', '')),
                QueryNormalizer::normalizeModel($this->identifiers->model($title)),
            ]);
            if ($offerModels !== [] || $title !== '') {
                $signals['model'] = $this->modelScore($queryModel, $offerModels, $title);
            }
        }

        if ($query->brand) {
            $offerBrand = IdentifierExtractor::canonicalBrand((string) $offer->get('brand', ''));
            $queryBrand = IdentifierExtractor::canonicalBrand($query->brand);
            if ($offerBrand) {
                $signals['brand'] = $offerBrand === $queryBrand || str_contains($offerBrand, (string) $queryBrand) ? 1.0 : 0.0;
            } elseif ($title !== '') {
                $signals['brand'] = str_contains(mb_strtolower($title), (string) $queryBrand) ? 1.0 : 0.0;
            }
        }

        $imageSim = ImageSimilarity::similarity($query->imagePhash, $offerImagePhash);
        if ($imageSim !== null) {
            $signals['image'] = $imageSim;
        }

        $offerAttrs = array_merge($this->identifiers->attributes($title), array_filter((array) $offer->get('variant_attributes', []), 'is_scalar'));
        $offerAttrs = array_map(fn ($v) => is_string($v) ? mb_strtolower($v) : $v, $offerAttrs);
        $compared = array_intersect_key($query->attributes, $offerAttrs);
        if ($compared !== []) {
            $matches = 0;
            foreach ($compared as $k => $v) {
                $matches += (string) $v === (string) $offerAttrs[$k] ? 1 : 0;
            }
            $signals['variant'] = $matches / count($compared);
        }

        if ($signals === []) {
            return new MatchResult(0.0, 'rejected', 'scored', ['reason' => 'no_signals']);
        }

        $weightSum = array_sum(array_intersect_key($weights, $signals));
        $score = 0.0;
        foreach ($signals as $k => $v) {
            $score += $v * ($weights[$k] / $weightSum);
        }

        $negatives = $this->hardNegatives->detect(
            $query->title ?? $query->cleanedTitle, $title, $query->attributes, $offerAttrs,
            $requestedCondition, ConditionMapper::map($offer->get('condition')),
        );
        if ($negatives !== []) {
            $score *= (float) config('pricelens.matching.hard_negative_multiplier', 0.3);
        }

        $score = round(max(0.0, min(1.0, $score)), 3);
        $breakdown = array_map(fn ($v) => round($v, 3), $signals);
        if ($negatives !== []) {
            $breakdown['hard_negatives'] = $negatives;
        }

        return new MatchResult($score, self::band($score, $bands), 'scored', $breakdown);
    }

    /** @param array<string, float> $bands */
    public static function band(float $score, array $bands): string
    {
        return match (true) {
            $score >= ($bands['exact'] ?? 0.85) => 'exact',
            $score >= ($bands['likely'] ?? 0.60) => 'likely',
            $score >= ($bands['possible'] ?? 0.40) => 'possible',
            default => 'rejected',
        };
    }

    /** @param array<int, string> $offerModels */
    private function modelScore(string $queryModel, array $offerModels, string $title): float
    {
        foreach ($offerModels as $m) {
            if ($m === $queryModel) {
                return 1.0;
            }
        }
        $flatTitle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $title) ?? '');
        if ($flatTitle !== '' && str_contains($flatTitle, $queryModel)) {
            return 1.0;
        }
        foreach ($offerModels as $m) {
            $short = min(strlen($m), strlen($queryModel));
            if ($short >= 4 && (str_starts_with($m, $queryModel) || str_starts_with($queryModel, $m))) {
                return 0.5;
            }
        }

        return 0.0;
    }
}
