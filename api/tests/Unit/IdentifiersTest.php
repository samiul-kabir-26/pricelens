<?php

use App\Scraping\Planning\GtinValidator;
use App\Scraping\Planning\IdentifierExtractor;
use App\Scraping\Planning\QueryNormalizer;

it('validates GTIN check digits', function (string $gtin, bool $valid) {
    expect(GtinValidator::isValid($gtin))->toBe($valid);
})->with([
    'UPC-A' => ['027242923782', true],
    'EAN-13 with leading zero' => ['0027242923782', true],
    'EAN-13' => ['4006381333931', true],
    'GTIN-8' => ['96385074', true],
    'GTIN-14' => ['10614141000415', true],
    'with dashes' => ['4006-3813-3393-1', true],
    'bad check digit' => ['4006381333932', false],
    'wrong length' => ['12345', false],
    'letters' => ['40063813339A1', false],
]);

it('compares GTINs across UPC/EAN forms', function () {
    expect(GtinValidator::equals('027242923782', '0027242923782'))->toBeTrue()
        ->and(GtinValidator::equals('027242923782', '4006381333931'))->toBeFalse();
});

it('cleans titles and normalizes units', function () {
    $n = new QueryNormalizer;
    expect($n->clean('NEW! Samsung 1 TB SSD — Free Shipping'))->toBe('samsung 1tb ssd')
        ->and($n->tokens('Apple iPhone 15 Pro 256 GB Black Titanium'))->toContain('256gb', 'iphone', '15');
});

it('extracts model, brand, gtin and attributes', function () {
    $x = new IdentifierExtractor(new QueryNormalizer);
    $title = 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black 0027242923782';

    expect($x->model($title, 'Sony'))->toBe('WH-1000XM5')
        ->and($x->brand($title))->toBe('Sony')
        ->and($x->gtin($title))->toBe('0027242923782')
        ->and($x->attributes('Galaxy S24 Ultra 512 GB Titanium Gray, pack of 2'))->toMatchArray(['storage' => '512gb', 'pack' => 2])
        ->and($x->attributes('MacBook Air 13.6" Midnight 1TB'))->toMatchArray(['storage' => '1024gb', 'color' => 'midnight'])
        ->and($x->model('Samsung 65W charger 2024 model'))->toBeNull();
});

it('resolves brand aliases', function () {
    $x = new IdentifierExtractor(new QueryNormalizer);
    expect($x->brand('Hewlett Packard LaserJet Pro'))->toBe('HP')
        ->and(IdentifierExtractor::canonicalBrand('WD'))->toBe('western digital');
});
