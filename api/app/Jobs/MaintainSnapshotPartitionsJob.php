<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monthly RANGE partitions for price_snapshots (MySQL only): keeps partitions created `months_ahead` into the
 * future by splitting p_future, and optionally drops months older than `retention_months`.
 * Partition names: pYYYYMM holds rows with observed_at < the first day of the following month.
 */
class MaintainSnapshotPartitionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function handle(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = collect(DB::select(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS bound FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL ORDER BY PARTITION_ORDINAL_POSITION',
            ['price_snapshots'],
        ))->keyBy('name');

        if ($existing->isEmpty() || ! $existing->has('p_future')) {
            Log::warning('price_snapshots is not partitioned as expected; skipping partition maintenance');

            return;
        }

        // Highest bound below MAXVALUE determines where the next monthly partition starts.
        $highest = $existing->reject(fn ($p) => $p->bound === 'MAXVALUE')->max(fn ($p) => (int) $p->bound);
        $cursor = Carbon::createFromTimestampUTC((int) $highest)->startOfMonth();
        $until = now()->utc()->startOfMonth()->addMonths((int) config('pricelens.monitoring.partition_months_ahead', 3));

        $created = [];
        while ($cursor->lte($until)) {
            $next = $cursor->copy()->addMonth();
            $name = 'p'.$cursor->format('Ym');
            if (! $existing->has($name)) {
                DB::statement(sprintf(
                    "ALTER TABLE price_snapshots REORGANIZE PARTITION p_future INTO (PARTITION %s VALUES LESS THAN (UNIX_TIMESTAMP('%s')), PARTITION p_future VALUES LESS THAN MAXVALUE)",
                    $name, $next->format('Y-m-d 00:00:00'),
                ));
                $created[] = $name;
            }
            $cursor = $next;
        }

        $dropped = [];
        $retention = config('pricelens.monitoring.snapshot_retention_months');
        if ($retention !== null && (int) $retention > 0) {
            $cutoff = now()->utc()->startOfMonth()->subMonths((int) $retention);
            foreach ($existing as $p) {
                if (preg_match('/^p(\d{6})$/', $p->name, $m) && Carbon::createFromFormat('Ym', $m[1], 'UTC')?->startOfMonth()->lt($cutoff)) {
                    DB::statement("ALTER TABLE price_snapshots DROP PARTITION {$p->name}");
                    $dropped[] = $p->name;
                }
            }
        }

        if ($created || $dropped) {
            Log::info('price_snapshots partitions maintained', ['created' => $created, 'dropped' => $dropped]);
        }
    }
}
