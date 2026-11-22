<?php

namespace App\Services;

use App\Models\Source;
use App\Models\Tenant;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rolling per-source health. Hourly windows in source_health_stats; status from the last 50 extract tasks:
 * < 50% success → failing (emits source.degraded; pauses global adapters), < 80% → degraded.
 */
class SourceHealthRecorder
{
    /** @param string $outcome ok|fail|blocked */
    public function record(Source $source, string $stage, string $outcome, ?int $durationMs = null): void
    {
        $column = match (true) {
            $outcome === 'blocked' => 'blocked',
            in_array($stage, ['fetch', 'render', 'discover'], true) => $outcome === 'ok' ? 'fetch_ok' : 'fetch_fail',
            default => $outcome === 'ok' ? 'extract_ok' : 'extract_fail',
        };

        $window = now()->utc()->startOfHour()->toDateTimeString();
        DB::table('source_health_stats')->insertOrIgnore(['source_id' => $source->id, 'window_start' => $window]);
        DB::table('source_health_stats')->where('source_id', $source->id)->where('window_start', $window)->increment($column);

        if ($stage === 'extract') {
            $this->evaluate($source);
        }
    }

    public function evaluate(Source $source): void
    {
        $recent = DB::table('search_tasks')->where('source_id', $source->id)->where('stage', 'extract')
            ->whereIn('status', ['succeeded', 'failed'])->latest('id')->limit(50)->pluck('status');

        if ($recent->count() < 10) {
            return;
        }

        $rate = $recent->filter(fn ($s) => $s === 'succeeded')->count() / $recent->count();
        $status = $rate < 0.5 ? 'failing' : ($rate < 0.8 ? 'degraded' : 'healthy');
        if ($status === $source->health_status) {
            return;
        }

        $previous = $source->health_status;
        $updates = ['health_status' => $status];
        if ($status === 'failing' && $source->is_global) {
            $updates['enabled'] = false; // paused pending review
            Log::warning('global_adapter_paused', ['source' => $source->ulid, 'success_rate' => $rate]);
        }
        Source::acrossTenants()->whereKey($source->id)->update($updates);

        if ($status === 'failing' && $previous !== 'failing') {
            $tenantIds = $source->is_global
                ? DB::table('tenant_global_sources')->where('source_id', $source->id)->pluck('tenant_id')->all()
                : [$source->tenant_id];
            foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $tenant) {
                app(WebhookDispatcher::class)->dispatch($tenant, 'source.degraded', [
                    'source_id' => $source->ulid, 'name' => $source->name, 'domain' => $source->domain,
                    'health_status' => $status, 'extraction_success_rate' => round($rate, 3), 'auto_paused' => $source->is_global,
                ]);
            }
        }
    }
}
