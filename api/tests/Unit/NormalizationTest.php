<?php

use App\Scraping\Normalization\AvailabilityMapper;
use App\Scraping\Normalization\ConditionMapper;
use App\Scraping\Normalization\PriceStats;
use App\Support\Money;

it('maps availability values', function (mixed $raw, string $expected) {
    expect(AvailabilityMapper::map($raw))->toBe($expected);
})->with([
    ['https://schema.org/InStock', 'in_stock'],
    ['http://schema.org/OutOfStock', 'out_of_stock'],
    ['PreOrder', 'preorder'],
    ['BackOrder', 'backorder'],
    ['LimitedAvailability', 'limited'],
    ['Discontinued', 'discontinued'],
    ['Only 3 left in stock', 'limited'],
    ['Sold out', 'out_of_stock'],
    ['Add to cart', 'in_stock'],
    ['Auf Lager', 'in_stock'],
    ['', 'unknown'],
    [null, 'unknown'],
    ['mystery', 'unknown'],
]);

it('maps condition values', function (string $raw, string $expected) {
    expect(ConditionMapper::map($raw))->toBe($expected);
})->with([
    ['https://schema.org/NewCondition', 'new'],
    ['RefurbishedCondition', 'refurbished'],
    ['Renewed', 'refurbished'],
    ['UsedCondition', 'used'],
    ['Open box', 'used'],
]);

it('formats minor units by currency precision', function () {
    expect(Money::format(32999, 'USD'))->toBe('329.99')
        ->and(Money::format(12800, 'JPY'))->toBe('12800')
        ->and(Money::format(1250, 'KWD'))->toBe('1.250')
        ->and(Money::toMinor('329.99', 'USD'))->toBe(32999);
});

it('flags outliers beyond median ± 3 MAD', function () {
    $flags = PriceStats::outliers(['a' => 32999, 'b' => 33500, 'c' => 32900, 'd' => 34900, 'e' => 1999, 'f' => 33100]);
    expect($flags['e'])->toBeTrue()->and($flags['a'])->toBeFalse()->and($flags['d'])->toBeFalse();
});

it('computes summary percentiles', function () {
    expect(PriceStats::summary([100, 200, 300, 400, 500]))->toBe(['min' => 100, 'max' => 500, 'median' => 300, 'mean' => 300, 'p25' => 200, 'p75' => 400]);
});
