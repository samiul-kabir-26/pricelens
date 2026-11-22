<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Tenant;
use App\Scraping\Extraction\ExtractionPipeline;
use App\Scraping\Fetching\Fetcher;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Capture a real page as an extractor golden fixture: tests/Fixtures/html/{name}.html + {name}.expected.json.
 * Review the expected JSON by hand before committing.
 */
class CaptureFixtureCommand extends Command
{
    protected $signature = 'fixtures:capture {url} {--tenant= : tenant slug (policies, proxies)} {--source= : source ULID for adapter selectors} {--name=} {--render}';

    protected $description = 'Capture an HTML fixture and its extracted fields for golden tests';

    public function handle(Fetcher $fetcher, ExtractionPipeline $pipeline): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::query()->orderBy('id')->firstOrFail();

        return CurrentTenant::instance()->runAs($tenant, function () use ($tenant, $fetcher, $pipeline) {
            $source = $this->option('source') ? Source::query()->where('ulid', $this->option('source'))->firstOrFail() : null;
            $url = (string) $this->argument('url');
            $page = $fetcher->fetch($tenant, $url, $source, ['fresh' => true, 'render' => (bool) $this->option('render')]);
            $product = $pipeline->run($page->body, $page->finalUrl, $source, $tenant, allowLlm: false);

            $name = $this->option('name') ?: Str::slug((string) parse_url($url, PHP_URL_HOST).'-'.Str::limit((string) parse_url($url, PHP_URL_PATH), 60, ''));
            $dir = base_path('tests/Fixtures/html');
            @mkdir($dir, 0775, true);
            file_put_contents("{$dir}/{$name}.html", $page->body);
            file_put_contents("{$dir}/{$name}.expected.json", json_encode([
                'url' => $page->finalUrl,
                'source' => $source?->ulid,
                'method' => $product->method(),
                'fields' => $product->fields(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

            $this->info("Saved tests/Fixtures/html/{$name}.html (+ .expected.json) — method: {$product->method()}");

            return self::SUCCESS;
        });
    }
}
