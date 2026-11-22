<?php

use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Matching\HardNegatives;
use App\Scraping\Matching\ImageSimilarity;
use App\Scraping\Matching\Matcher;
use App\Scraping\Matching\TitleSimilarity;
use App\Scraping\Planning\IdentifierExtractor;
use App\Scraping\Planning\QueryNormalizer;

function matcher(): Matcher
{
    $n = new QueryNormalizer;

    return new Matcher(new TitleSimilarity($n), new HardNegatives, new IdentifierExtractor($n));
}

function query(string $title, array $extra = []): ProductQuery
{
    $n = new QueryNormalizer;
    $x = new IdentifierExtractor($n);

    return new ProductQuery(...array_merge([
        'title' => $title, 'cleanedTitle' => $n->clean($title), 'tokens' => $n->tokens($title),
        'brand' => $x->brand($title), 'model' => $x->model($title, $x->brand($title)), 'attributes' => $x->attributes($title),
    ], $extra));
}

it('short-circuits on GTIN equality and mismatch', function () {
    $q = query('Sony WH-1000XM5', ['gtin' => '0027242923782']);

    $same = matcher()->score($q, ExtractedProduct::make(['title' => 'whatever', 'gtin' => '027242923782'], 'json_ld'));
    $diff = matcher()->score($q, ExtractedProduct::make(['title' => 'Sony WH-1000XM5', 'gtin' => '4006381333931'], 'json_ld'));

    expect($same->confidence)->toBe(1.0)->and($same->type)->toBe('exact_gtin')->and($same->band)->toBe('exact')
        ->and($diff->confidence)->toBe(0.0)->and($diff->type)->toBe('mismatch_gtin');
});

it('scores a matching listing as exact with an explainable breakdown', function () {
    $r = matcher()->score(
        query('Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black'),
        ExtractedProduct::make(['title' => 'Sony WH-1000XM5 Wireless Headphones - Black', 'brand' => 'Sony', 'mpn' => 'WH-1000XM5'], 'json_ld'),
    );

    expect($r->band)->toBe('exact')->and($r->breakdown)->toHaveKeys(['title', 'model', 'brand', 'variant'])
        ->and($r->breakdown['model'])->toBe(1.0);
});

it('applies hard negatives to accessories', function () {
    $r = matcher()->score(
        query('Sony WH-1000XM5 Headphones'),
        ExtractedProduct::make(['title' => 'Hard Carrying Case for Sony WH-1000XM5 Headphones', 'brand' => 'CaseCo'], 'json_ld'),
    );

    expect($r->band)->toBe('rejected')->and($r->breakdown['hard_negatives'])->toContain('accessory:case');
});

it('penalizes conflicting storage and used condition when new is requested', function () {
    $q = query('Apple iPhone 15 Pro 256GB Black Titanium');
    $storage = matcher()->score($q, ExtractedProduct::make(['title' => 'Apple iPhone 15 Pro 128GB Black Titanium', 'brand' => 'Apple'], 'json_ld'));
    $used = matcher()->score($q, ExtractedProduct::make(['title' => 'Apple iPhone 15 Pro 256GB Black Titanium', 'brand' => 'Apple', 'condition' => 'UsedCondition'], 'json_ld'), 'new');

    expect($storage->breakdown['hard_negatives'])->toContain('conflicting_storage')->and($storage->band)->not->toBe('exact')
        ->and($used->breakdown['hard_negatives'])->toContain('condition:used');
});

it('does not reward unrelated titles', function () {
    expect(TitleSimilarity::tokenSetRatio(['light', 'attic'], ['tipping', 'velvet']))->toBeLessThan(0.2);
});

it('computes pHash similarity from hex hashes', function () {
    expect(ImageSimilarity::similarity('ffffffffffffffff', 'ffffffffffffffff'))->toBe(1.0)
        ->and(ImageSimilarity::hamming('ffffffffffffffff', 'fffffffffffffff0'))->toBe(4)
        ->and(ImageSimilarity::similarity('0000000000000000', 'ffffffffffffffff'))->toBe(0.0);
});

it('maps scores to bands', function () {
    $bands = ['exact' => 0.85, 'likely' => 0.6, 'possible' => 0.4];
    expect(Matcher::band(0.9, $bands))->toBe('exact')->and(Matcher::band(0.7, $bands))->toBe('likely')
        ->and(Matcher::band(0.5, $bands))->toBe('possible')->and(Matcher::band(0.1, $bands))->toBe('rejected');
});
