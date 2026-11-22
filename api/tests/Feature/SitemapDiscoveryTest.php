<?php

use App\Models\SitemapEntry;
use App\Models\Source;
use App\Scraping\Discovery\SitemapIndexer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakePublicDns();
    config(['pricelens.sitemaps.seconds_between_files' => 0]);
});

function sitemapXml(array $urls, bool $index = false): string
{
    $tag = $index ? 'sitemap' : 'url';
    $body = implode('', array_map(fn ($u) => "<{$tag}><loc>{$u}</loc><lastmod>2026-10-01</lastmod></{$tag}>", $urls));

    return '<?xml version="1.0" encoding="UTF-8"?><'.($index ? 'sitemapindex' : 'urlset').' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .$body.'</'.($index ? 'sitemapindex' : 'urlset').'>';
}

/** @param array<int, string> $products by reference, so a test can change the sitemap between refreshes */
function fakeSitemapShop(array &$products, string $robots = "User-agent: *\nDisallow: /search\n"): void
{
    Http::fake([
        'shop.example.com/robots.txt' => Http::response($robots),
        'shop.example.com/sitemap.xml' => Http::response(sitemapXml([
            'https://shop.example.com/sitemap-products-1.xml.gz',
            'https://shop.example.com/sitemap-blog.xml',
        ], index: true)),
        'shop.example.com/sitemap-products-1.xml.gz' => function () use (&$products) {
            return Http::response(gzencode(sitemapXml($products)), 200, ['Content-Type' => 'application/gzip']);
        },
        'shop.example.com/sitemap-blog.xml' => Http::response(sitemapXml(['https://shop.example.com/blog/tennis-ball-care-tips'])),
        'shop.example.com/p/sony-wh-1000xm5-black*' => Http::response(htmlFixture('jsonld-graph-aggregate.html')),
        'shop.example.com/search*' => Http::response('should never be fetched', 500),
        '*' => Http::response('not found', 404),
    ]);
}

function sitemapSource($tenant): Source
{
    return asTenant($tenant, fn () => Source::query()->create([
        'type' => 'site', 'name' => 'Sitemap Shop', 'domain' => 'shop.example.com', 'currency' => 'USD', 'render' => 'never',
        'config' => [
            'sitemap_urls' => ['https://shop.example.com/sitemap.xml'],
            'sitemap_child_pattern' => 'sitemap-products-\d+',
        ],
    ]));
}

it('derives searchable titles from product URL slugs', function (string $url, string $title) {
    expect(SitemapIndexer::titleFromUrl($url))->toBe($title);
})->with([
    'plain slug' => ['https://shop.test/emami-7-oils-in-one-pumpkin-plus-hair-oil-200ml', 'emami 7 oils in one pumpkin plus hair oil 200ml'],
    'id segment' => ['https://shop.test/product/365017/pro-young-tennis-ball-cricket-special-1-can', 'pro young tennis ball cricket special 1 can'],
    'id + hash suffix' => ['https://shop.test/product/chair_wheel_5pc_166570935804140_oi44h8', 'chair wheel 5pc'],
    'keeps model numbers' => ['https://shop.test/p/sony-wh-1000xm5-black.html', 'sony wh 1000xm5 black'],
    'id-only url' => ['https://shop.test/i1225830-s23015724.html', ''],
    'percent-encoded' => ['https://shop.test/product/%E0%A6%AC%E0%A6%B2-ball', 'বল ball'],
]);

it('indexes gzipped child sitemaps, follows only matching children and prunes stale entries', function () {
    [$tenant] = tenantWithKey();
    $products = [
        'https://shop.example.com/p/sony-wh-1000xm5-black',
        'https://shop.example.com/p/wilson-tennis-ball-3-pcs',
        'https://shop.example.com/i1225830-s23015724.html',
    ];
    fakeSitemapShop($products);
    $source = sitemapSource($tenant);

    $this->artisan('pricelens:sitemaps:refresh', ['source' => $source->ulid, '--sync' => true])->assertSuccessful();

    expect(SitemapEntry::query()->where('source_id', $source->id)->pluck('title')->sort()->values()->all())
        ->toBe(['sony wh 1000xm5 black', 'wilson tennis ball 3 pcs']) // blog sitemap skipped, id-only URL skipped
        ->and($source->fresh()->sitemap_refreshed_at)->not->toBeNull();

    // The shop drops a product: the next full refresh prunes it.
    $products = ['https://shop.example.com/p/sony-wh-1000xm5-black'];
    $this->travel(5)->seconds();
    $this->artisan('pricelens:sitemaps:refresh', ['source' => $source->ulid, '--sync' => true])->assertSuccessful();

    expect(SitemapEntry::query()->where('source_id', $source->id)->pluck('title')->all())->toBe(['sony wh 1000xm5 black']);
});

it('does not index sitemaps that robots.txt disallows', function () {
    [$tenant] = tenantWithKey();
    $products = ['https://shop.example.com/p/sony-wh-1000xm5-black'];
    fakeSitemapShop($products, "User-agent: *\nDisallow: /\n");
    $source = sitemapSource($tenant);

    $this->artisan('pricelens:sitemaps:refresh', ['source' => $source->ulid, '--sync' => true])->assertFailed();

    expect(SitemapEntry::query()->where('source_id', $source->id)->count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sitemap'));
});

it('discovers candidates from the sitemap index without fetching the disallowed search page', function () {
    [$tenant, $key] = tenantWithKey();
    $products = [
        'https://shop.example.com/p/sony-wh-1000xm5-black',
        'https://shop.example.com/p/wilson-tennis-ball-3-pcs',
    ];
    fakeSitemapShop($products);
    $source = sitemapSource($tenant);
    $this->artisan('pricelens:sitemaps:refresh', ['source' => $source->ulid, '--sync' => true])->assertSuccessful();

    $id = $this->postJson('/api/v1/searches', [
        'title' => 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black',
        'sources' => ['source_ids' => [$source->ulid]],
        'options' => ['target_currency' => 'USD'],
    ], bearer($key))->assertStatus(202)->json('data.id');

    $search = $this->getJson("/api/v1/searches/{$id}", bearer($key))->assertOk()->json('data');
    expect($search['status'])->toBe('completed')
        ->and($search['sources'][0])->toMatchArray(['name' => 'Sitemap Shop', 'status' => 'completed', 'offers_found' => 1]);

    $offers = $this->getJson("/api/v1/searches/{$id}/offers", bearer($key))->assertOk()->json('data');
    expect($offers[0]['url'])->toBe('https://shop.example.com/p/sony-wh-1000xm5-black');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/search'));
});

it('reports an unindexed sitemap source instead of silently returning nothing', function () {
    [$tenant, $key] = tenantWithKey();
    $products = [];
    fakeSitemapShop($products);
    $source = sitemapSource($tenant);

    $id = $this->postJson('/api/v1/searches', [
        'title' => 'Wilson tennis ball',
        'sources' => ['source_ids' => [$source->ulid]],
    ], bearer($key))->assertStatus(202)->json('data.id');

    expect($this->getJson("/api/v1/searches/{$id}", bearer($key))->json('data.sources.0.error'))->toBe('sitemap_not_indexed');
});

it('validates sitemap adapter config', function () {
    [, $key] = tenantWithKey();

    $this->postJson('/api/v1/sources', [
        'type' => 'site', 'name' => 'Bad', 'domain' => 'shop.example.com',
        'config' => ['sitemap_urls' => ['ftp://shop.example.com/sitemap.xml'], 'sitemap_child_pattern' => '(a+)+$('],
    ], bearer($key))->assertStatus(422)->assertJsonValidationErrors(['config.sitemap_urls.0', 'config.sitemap_child_pattern']);
});

it('treats a urlset that only lists sitemap files as an index', function () {
    expect(SitemapIndexer::isIndex(sitemapXml(['https://s.test/sitemap-product-1.xml.gz', 'https://s.test/sitemap-product-2.xml.gz'])))->toBeTrue()
        ->and(SitemapIndexer::isIndex(sitemapXml(['https://s.test/p/ball', 'https://s.test/sitemap-2.xml'])))->toBeFalse()
        ->and(SitemapIndexer::isIndex(sitemapXml(['https://s.test/a'], index: true)))->toBeTrue();
});
