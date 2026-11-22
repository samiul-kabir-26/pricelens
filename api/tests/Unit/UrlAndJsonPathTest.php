<?php

use App\Scraping\Extraction\JsonPath;
use App\Support\Url;

it('canonicalizes URLs and strips tracking params', function () {
    expect(Url::canonicalize('HTTPS://Shop.Example.com:443/p/xm5/?utm_source=a&ref=b&gclid=c&color=black&b=1#reviews', ['utm_*', 'ref', 'gclid']))
        ->toBe('https://shop.example.com/p/xm5?b=1&color=black');
});

it('absolutizes relative links', function () {
    expect(Url::absolutize('/p/1', 'https://a.com/search?q=x'))->toBe('https://a.com/p/1')
        ->and(Url::absolutize('../p/2', 'https://a.com/c/d/e'))->toBe('https://a.com/c/p/2')
        ->and(Url::absolutize('//cdn.a.com/i.jpg', 'https://a.com/'))->toBe('https://cdn.a.com/i.jpg')
        ->and(Url::absolutize('javascript:void(0)', 'https://a.com/'))->toBeNull();
});

it('reads JSON paths', function () {
    $data = ['props' => ['items' => [['price' => 1], ['price' => 2]], 'deep' => ['x' => ['sku' => 'S1']]]];

    expect(JsonPath::get($data, '$.props.items[1].price'))->toBe(2)
        ->and(JsonPath::get($data, '$.props.items[*].price'))->toBe(1)
        ->and(JsonPath::get($data, '$..sku'))->toBe('S1')
        ->and(JsonPath::get($data, '$.missing.path'))->toBeNull()
        ->and(JsonPath::decodeLenient('window.__STATE__ = {"a": [1, 2,],};'))->toBe(['a' => [1, 2]]);
});
