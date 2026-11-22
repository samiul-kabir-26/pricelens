<?php

use App\Scraping\Normalization\PriceParser;

it('parses locale-formatted prices into minor units', function (mixed $raw, ?string $hint, int $minor, ?string $currency) {
    expect((new PriceParser)->parse($raw, $hint))->toBe(['minor' => $minor, 'currency' => $currency]);
})->with([
    'EU decimal comma' => ['1.299,00 €', null, 129900, 'EUR'],
    'US' => ['€1,299.00', null, 129900, 'EUR'],
    'space thousands' => ['1 299,00', 'EUR', 129900, 'EUR'],
    'nbsp thousands' => ["1\u{00A0}299,50\u{00A0}€", null, 129950, 'EUR'],
    'Indian grouping' => ['Rs. 1,29,999', null, 12999900, 'INR'],
    'rupee sign' => ['₹1,29,999.50', null, 12999950, 'INR'],
    'yen, zero decimals' => ['¥12,800', null, 12800, 'JPY'],
    'KWD three decimals' => ['KWD 1.250', null, 1250, 'KWD'],
    'dollar with CAD hint' => ['$49.99', 'CAD', 4999, 'CAD'],
    'US$ explicit' => ['US$ 49.99', 'CAD', 4999, 'USD'],
    'pound' => ['£51.77', null, 5177, 'GBP'],
    'integer JSON value' => [899, 'EUR', 89900, 'EUR'],
    'float JSON value' => [749.99, 'USD', 74999, 'USD'],
    'single comma thousands' => ['$1,299', null, 129900, 'USD'],
    'swiss apostrophe' => ["CHF 1'299.90", null, 129990, 'CHF'],
    'kr suffix' => ['1 299 kr', 'NOK', 129900, 'NOK'],
    'text around' => ['Now only $329.99!', null, 32999, 'USD'],
]);

it('treats structured values as dot-decimal', function () {
    expect((new PriceParser)->parse('1.299', 'USD', structured: true))->toBe(['minor' => 130, 'currency' => 'USD']);
});

it('rejects non-positive and non-numeric values', function (mixed $raw) {
    expect((new PriceParser)->parse($raw, 'USD'))->toBeNull();
})->with(['0', '0.00', 'Call for price', '', null, -5]);
