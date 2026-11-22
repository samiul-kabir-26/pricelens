<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use Symfony\Component\DomCrawler\Crawler;

interface Extractor
{
    public function name(): string;

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct;
}
