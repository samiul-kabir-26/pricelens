<?php

namespace App\Jobs;

use App\Jobs\Middleware\TenantAware;
use App\Models\FeedItem;
use App\Models\Source;
use App\Scraping\Fetching\SafeHttpClient;
use App\Scraping\Planning\GtinValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Index a merchant feed (Google Merchant CSV/TSV or RSS/Atom XML with g: fields) into feed_items.
 */
class RefreshFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $sourceId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(SafeHttpClient $http): void
    {
        $source = Source::query()->where('type', 'feed')->where('tenant_id', $this->tenantId)->find($this->sourceId);
        if (! $source) {
            return;
        }

        $content = $source->cfg('upload_path')
            ? Storage::disk(config('filesystems.default'))->get((string) $source->cfg('upload_path'))
            : $http->get((string) $source->cfg('feed_url'), ['max_bytes' => 50 * 1024 * 1024, 'timeout' => 60,
                'headers' => ['User-Agent' => config('pricelens.scraper.user_agent')]])['response']->body();

        if (! $content) {
            throw new RuntimeException('Feed is empty or unavailable.');
        }

        $format = (string) $source->cfg('format', str_starts_with(ltrim($content), '<') ? 'xml' : 'csv');
        $items = $format === 'xml' ? self::parseXml($content) : self::parseCsv($content);

        $seen = [];
        foreach (array_chunk($items, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $item) {
                if (empty($item['id']) || empty($item['title']) || empty($item['link'])) {
                    continue;
                }
                $gtin = GtinValidator::normalize($item['gtin'] ?? null);
                $seen[] = (string) $item['id'];
                $rows[] = [
                    'tenant_id' => $this->tenantId, 'source_id' => $source->id, 'item_id' => mb_substr((string) $item['id'], 0, 255),
                    'title' => mb_substr((string) $item['title'], 0, 512), 'brand' => $item['brand'] ?? null,
                    'gtin' => $gtin && GtinValidator::isValid($gtin) ? $gtin : null, 'mpn' => $item['mpn'] ?? null,
                    'link' => $item['link'], 'image_link' => $item['image_link'] ?? null, 'price' => $item['price'] ?? null,
                    'sale_price' => $item['sale_price'] ?? null, 'availability' => $item['availability'] ?? null,
                    'condition' => $item['condition'] ?? null, 'shipping' => $item['shipping'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            FeedItem::query()->upsert($rows, ['source_id', 'item_id'], ['title', 'brand', 'gtin', 'mpn', 'link', 'image_link', 'price', 'sale_price', 'availability', 'condition', 'shipping', 'updated_at']);
        }

        FeedItem::query()->where('source_id', $source->id)->whereNotIn('item_id', $seen ?: [''])->delete();
        $source->forceFill(['feed_refreshed_at' => now()])->save();
    }

    /** @return array<int, array<string, string>> */
    public static function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        if ($lines === []) {
            return [];
        }
        $delimiter = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
        $headers = array_map(fn (?string $h) => strtolower(trim(preg_replace('/^(g:|\xEF\xBB\xBF)/', '', $h ?? '') ?? $h ?? '')), str_getcsv(array_shift($lines), $delimiter, '"', ''));

        $items = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = array_map(fn (?string $v) => $v ?? '', str_getcsv($line, $delimiter, '"', ''));
            $items[] = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), ''));
        }

        return $items;
    }

    /** @return array<int, array<string, string>> */
    public static function parseXml(string $content): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException('Invalid feed XML.');
        }

        $nodes = $xml->xpath('//item') ?: ($xml->xpath('//*[local-name()="entry"]') ?: []);
        $items = [];
        foreach ($nodes as $node) {
            $row = [];
            foreach ([null, 'http://base.google.com/ns/1.0'] as $ns) {
                foreach ($node->children($ns) as $child) {
                    $row[strtolower($child->getName())] ??= trim((string) $child);
                }
            }
            if (! isset($row['link']) && isset($node->link['href'])) {
                $row['link'] = (string) $node->link['href'];
            }
            $items[] = $row;
        }

        return $items;
    }
}
