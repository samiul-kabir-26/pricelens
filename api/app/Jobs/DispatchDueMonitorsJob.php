<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Models\Tenant;
use App\Services\MonitorRunner;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: claim due monitors (FOR UPDATE SKIP LOCKED on MySQL) in chunks, advance next_run_at and
 * start a search for each.
 */
class DispatchDueMonitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public function handle(MonitorRunner $runner): void
    {
        do {
            $claimed = DB::transaction(function () {
                $query = Monitor::acrossTenants()->where('status', 'active')->where('next_run_at', '<=', now())
                    ->orderBy('next_run_at')->limit(100);
                $monitors = (DB::getDriverName() === 'mysql' ? $query->lock('FOR UPDATE SKIP LOCKED') : $query)->get();

                foreach ($monitors as $monitor) {
                    $monitor->forceFill(['next_run_at' => $monitor->computeNextRun(), 'last_run_at' => now()])->save();
                }

                return $monitors;
            });

            foreach ($claimed as $monitor) {
                $tenant = Tenant::query()->with('plan')->find($monitor->tenant_id);
                if (! $tenant?->isActive()) {
                    continue;
                }
                try {
                    CurrentTenant::instance()->runAs($tenant, fn () => $runner->run($tenant, $monitor));
                } catch (Throwable $e) {
                    Log::warning('monitor_run_failed', ['monitor' => $monitor->ulid, 'error' => $e->getMessage()]);
                }
            }
        } while ($claimed->count() === 100);
    }
}
