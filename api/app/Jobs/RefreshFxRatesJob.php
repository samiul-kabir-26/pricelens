<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Daily ECB euro reference rates → fx_rates (base EUR).
 */
class RefreshFxRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public function handle(): void
    {
        $response = Http::timeout(20)->get(config('pricelens.fx.ecb_url'));
        if (! $response->successful()) {
            throw new RuntimeException('ECB rates unavailable: HTTP '.$response->status());
        }

        $rows = self::parse($response->body());
        if ($rows === []) {
            throw new RuntimeException('ECB rates response contained no rates.');
        }

        DB::table('fx_rates')->upsert($rows, ['date', 'base', 'quote'], ['rate']);
        Cache::forget('fx:'.$rows[0]['date']);
    }

    /** @return array<int, array{date: string, base: string, quote: string, rate: float}> */
    public static function parse(string $xml): array
    {
        if (! preg_match("/<Cube time=['\"](\d{4}-\d{2}-\d{2})['\"]/", $xml, $d)) {
            return [];
        }
        preg_match_all("/<Cube currency=['\"]([A-Z]{3})['\"] rate=['\"]([\d.]+)['\"]/", $xml, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => ['date' => $d[1], 'base' => 'EUR', 'quote' => $r[1], 'rate' => (float) $r[2]], $m);
    }
}
