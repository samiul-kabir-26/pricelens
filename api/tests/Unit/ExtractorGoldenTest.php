<?php

use App\Models\Source;
use App\Scraping\Extraction\ExtractionPipeline;

/**
 * Golden tests: every tests/Fixtures/html/*.expected.json is replayed through the full extractor chain.
 * Add fixtures with `php artisan fixtures:capture {url}`.
 */
$fixtures = collect(glob(__DIR__.'/../Fixtures/html/*.expected.json'))
    ->mapWithKeys(fn ($f) => [basename($f, '.expected.json') => [basename($f, '.expected.json')]])->all();

it('extracts the expected fields', function (string $name) {
    $expected = json_decode(htmlFixture("{$name}.expected.json"), true);
    $source = null;
    if (isset($expected['source_config'])) {
        $source = new Source(['type' => 'site', 'config' => $expected['source_config'], 'render' => 'never']);
    }

    $product = app(ExtractionPipeline::class)->run(htmlFixture("{$name}.html"), $expected['url'], $source, null, allowLlm: false);

    expect($product->method())->toBe($expected['method']);
    foreach ($expected['fields'] as $field => $value) {
        expect($product->get($field))->toEqual($value, "field {$field} in {$name}");
    }
})->with($fixtures);

it('reports not_product_page for pages without product data', function () {
    $product = app(ExtractionPipeline::class)->run('<html><body><h1>Welcome</h1><a href="/c">Shop</a></body></html>', 'https://x.example.com/', null, null, false);

    expect(ExtractionPipeline::failureReason($product))->toBe('not_product_page');
});
