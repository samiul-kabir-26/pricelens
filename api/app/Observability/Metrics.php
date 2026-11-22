<?php

namespace App\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * Minimal Prometheus registry backed by the cache store (Redis in production), so counters are shared
 * across PHP-FPM and Horizon processes. Exposed at GET /metrics.
 */
final class Metrics
{
    private const INDEX = 'metrics:index';

    /** @var array<string, array<int, int|float>> */
    public const HISTOGRAM_BUCKETS = [
        'pricelens_search_duration_seconds' => [1, 2.5, 5, 10, 15, 30, 45, 60, 120, 300],
    ];

    /** @param array<string, string> $labels */
    public static function increment(string $name, array $labels = [], int $by = 1): void
    {
        try {
            $key = self::key($name, $labels);
            if (Cache::add($key, 0, now()->addDays(30))) {
                self::index($name, $labels, 'counter');
            }
            Cache::increment($key, $by);
        } catch (Throwable) {
            // Metrics must never break the pipeline.
        }
    }

    /** @param array<string, string> $labels */
    public static function observe(string $name, float $value, array $labels = []): void
    {
        foreach (self::HISTOGRAM_BUCKETS[$name] ?? [] as $le) {
            if ($value <= $le) {
                self::increment($name.'_bucket', $labels + ['le' => (string) $le]);
            }
        }
        self::increment($name.'_bucket', $labels + ['le' => '+Inf']);
        self::increment($name.'_count', $labels);
        self::increment($name.'_sum_ms', $labels, (int) round($value * 1000));
    }

    public static function render(): string
    {
        $index = Cache::get(self::INDEX, []);
        ksort($index);
        $lines = [];
        $typed = [];

        foreach ($index as $key => $meta) {
            $name = $meta['name'];
            $value = (int) Cache::get($key, 0);
            $base = preg_replace('/_(bucket|count|sum_ms)$/', '', $name);
            $isHistogram = isset(self::HISTOGRAM_BUCKETS[$base]);

            if ($isHistogram && str_ends_with($name, '_sum_ms')) {
                $name = $base.'_sum';
                $value = $value / 1000;
            }
            $family = $isHistogram ? $base : $name;
            if (! isset($typed[$family])) {
                $lines[] = "# TYPE {$family} ".($isHistogram ? 'histogram' : 'counter');
                $typed[$family] = true;
            }

            $labelSet = $meta['labels'] ?? [];
            $labels = collect(is_array($labelSet) ? $labelSet : [])
                ->map(fn ($v, $k) => $k.'="'.addcslashes((string) $v, "\"\\\n").'"')->implode(',');
            $lines[] = $name.($labels !== '' ? '{'.$labels.'}' : '').' '.$value;
        }

        return implode("\n", array_merge($lines, self::queueGauges()))."\n";
    }

    /**
     * Point-in-time gauges computed at scrape time: queue depth and Horizon's estimated wait per queue.
     *
     * @return array<int, string>
     */
    private static function queueGauges(): array
    {
        $queues = ['orchestration', 'discovery', 'fetch', 'render', 'extract', 'webhooks', 'default'];
        $depth = ['# TYPE pricelens_queue_depth gauge'];
        $wait = ['# TYPE pricelens_queue_wait_seconds gauge'];
        $connection = (string) config('queue.default');

        try {
            $waits = config('queue.default') === 'redis' && class_exists(WaitTimeCalculator::class)
                ? app(WaitTimeCalculator::class)->calculate() : [];
        } catch (Throwable) {
            $waits = [];
        }

        foreach ($queues as $queue) {
            try {
                $size = Queue::size($queue);
            } catch (Throwable) {
                continue;
            }
            $depth[] = "pricelens_queue_depth{queue=\"{$queue}\"} {$size}";
            $wait[] = "pricelens_queue_wait_seconds{queue=\"{$queue}\"} ".(int) ($waits["{$connection}:{$queue}"] ?? 0);
        }

        return array_merge($depth, $wait);
    }

    /** @param array<string, string> $labels */
    private static function key(string $name, array $labels): string
    {
        ksort($labels);

        return 'metrics:'.$name.':'.md5(json_encode($labels) ?: '');
    }

    /** @param array<string, string> $labels */
    private static function index(string $name, array $labels, string $type): void
    {
        ksort($labels);
        $lock = Cache::lock('metrics:index:lock', 5);
        $lock->block(2, function () use ($name, $labels, $type) {
            $index = Cache::get(self::INDEX, []);
            $index[self::key($name, $labels)] = ['name' => $name, 'labels' => $labels, 'type' => $type];
            Cache::forever(self::INDEX, $index);
        });
    }
}
