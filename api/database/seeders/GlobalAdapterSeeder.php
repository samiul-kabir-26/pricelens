<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Platform adapter library (tenant_id NULL, is_global). Starts with public scraping sandboxes for demos;
 * production markets are an open item (spec §25).
 */
class GlobalAdapterSeeder extends Seeder
{
    public function run(): void
    {
        $adapters = [
            [
                'name' => 'Books to Scrape (sandbox)',
                'domain' => 'books.toscrape.com',
                'country' => 'GB',
                'currency' => 'GBP',
                'render' => 'never',
                'config' => [
                    'product_url_patterns' => ['^https://books\\.toscrape\\.com/catalogue/[\\w-]+_\\d+/index\\.html$'],
                    'rate_limit' => ['requests' => 1, 'per_seconds' => 1, 'concurrency' => 2],
                    'product' => [
                        'title' => 'div.product_main h1',
                        'price' => 'div.product_main p.price_color',
                        'currency' => ['static' => 'GBP'],
                        'availability' => 'div.product_main p.availability',
                        'sku' => "xpath://table//th[text()='UPC']/following-sibling::td",
                        'image' => '#product_gallery img@src',
                    ],
                ],
            ],
            [
                'name' => 'Web Scraper e-commerce test site (sandbox)',
                'domain' => 'webscraper.io',
                'country' => 'US',
                'currency' => 'USD',
                'render' => 'never',
                'config' => [
                    'product_url_patterns' => ['^https://webscraper\\.io/test-sites/e-commerce/(allinone|static)/product/\\d+$'],
                    'product' => [
                        'title' => '.caption h4.title, h4.title.card-title',
                        'price' => '.caption h4.price, h4.price',
                        'currency' => ['static' => 'USD'],
                        'rating' => 'p[data-rating]@data-rating',
                        'image' => 'img.img-responsive@src',
                    ],
                ],
            ],
            ...self::bangladesh(),
        ];

        foreach ($adapters as $a) {
            Source::acrossTenants()->updateOrCreate(
                ['tenant_id' => null, 'is_global' => true, 'domain' => $a['domain']],
                $a + ['type' => 'site', 'enabled' => true, 'health_status' => 'healthy'],
            );
        }
    }

    /**
     * Bangladesh market. Most of these shops disallow their search pages in robots.txt, so discovery uses their
     * product sitemaps (indexed by `pricelens:sitemaps:refresh`); Othoba allows on-site search.
     * Not included: Daraz (sitemap URLs carry no product names), Pickaboo, Ryans and TechLand (they refuse the
     * bot with HTTP 403), PriyoShop (now a B2B site), Sajgoj (sajgoj.com is parked; sajgoj.com.bd is unrelated).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function bangladesh(): array
    {
        $polite = ['requests' => 1, 'per_seconds' => 2, 'concurrency' => 2];
        $bd = ['country' => 'BD', 'currency' => 'BDT'];

        return [
            $bd + [
                'name' => 'Star Tech',
                'domain' => 'www.startech.com.bd',
                'render' => 'never',
                'config' => [
                    'sitemap_urls' => ['https://www.startech.com.bd/sitemap.xml'],
                    'rate_limit' => $polite,
                    'product' => [
                        'title' => 'h1.product-name',
                        'price' => 'td.product-price',
                        'currency' => ['static' => 'BDT'],
                        'availability' => 'td.product-status',
                        'sku' => 'td.product-code',
                        'brand' => 'td.product-brand',
                    ],
                ],
            ],
            $bd + [
                'name' => 'Cartup',
                'domain' => 'www.cartup.com',
                'render' => 'auto',
                'config' => [
                    'sitemap_urls' => ['https://www.cartup.com/sitemap-product-all.xml.gz'],
                    'rate_limit' => $polite,
                ],
            ],
            $bd + [
                'name' => 'Shwapno',
                'domain' => 'www.shwapno.com',
                'render' => 'never',
                'config' => [
                    'sitemap_urls' => ['https://www.shwapno.com/sitemap-products.xml'],
                    'rate_limit' => $polite,
                    'product' => [
                        'title' => 'h1#product-name',
                        // The price only exists in the Next.js flight data of the page.
                        'price' => ['selector' => 'xpath://script[contains(., "productReviewOverview")]', 'regex' => 'productReviewOverview.{0,300}?\\\\"priceValue\\\\":([0-9.]+)'],
                        'currency' => ['static' => 'BDT'],
                    ],
                ],
            ],
            $bd + [
                'name' => 'Rokomari',
                'domain' => 'www.rokomari.com',
                'render' => 'never',
                'config' => [
                    'sitemap_urls' => ['https://www.rokomari.com/sitemap.xml'],
                    'sitemap_child_pattern' => '/product_urls_\d+\.xml',
                    'rate_limit' => $polite,
                ],
            ],
            $bd + [
                'name' => 'Bangla Shoppers',
                'domain' => 'www.banglashoppers.com',
                'render' => 'never',
                'config' => [
                    'sitemap_urls' => ['https://www.banglashoppers.com/sitemap.xml'],
                    'sitemap_url_pattern' => '^https://www\.banglashoppers\.com/[^/]+\.html$',
                    'rate_limit' => $polite,
                ],
            ],
            $bd + [
                'name' => 'Othoba',
                'domain' => 'othoba.com',
                'render' => 'never',
                'config' => [
                    'search_url_template' => 'https://othoba.com/ts/search/{query}?t=t&q={query}',
                    'rate_limit' => $polite,
                    'listing' => [
                        'item' => '.product-details',
                        'item_link' => 'h4.product-name a@href',
                        'item_title' => 'h4.product-name a',
                    ],
                ],
            ],
        ];
    }
}
