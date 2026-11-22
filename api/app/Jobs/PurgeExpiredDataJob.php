<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data retention: searches (+ offers/tasks via cascade), HTML snapshots and webhook logs older than each
 * tenant's retention_days. Price snapshots are kept (monitoring history).
 */
class PurgeExpiredDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function handle(): void
    {
        $disk = Storage::disk(config('filesystems.snapshots_disk', config('filesystems.default')));

        Tenant::query()->each(function (Tenant $tenant) use ($disk) {
            $cutoff = now()->subDays(max(1, $tenant->retention_days));

            DB::table('searches')->where('tenant_id', $tenant->id)->where('created_at', '<', $cutoff)
                ->orderBy('id')->chunkById(500, function ($rows) {
                    DB::table('searches')->whereIn('id', $rows->pluck('id'))->delete();
                });
            DB::table('webhook_deliveries')->where('tenant_id', $tenant->id)->where('created_at', '<', $cutoff)->delete();
            DB::table('usage_records')->where('tenant_id', $tenant->id)->where('created_at', '<', now()->subMonths(13))->delete();

            foreach ($disk->directories("{$tenant->storagePrefix()}/snapshots") as $dir) {
                foreach ($disk->files($dir) as $file) {
                    if ($disk->lastModified($file) < $cutoff->getTimestamp()) {
                        $disk->delete($file);
                    }
                }
            }
        });
    }
}
