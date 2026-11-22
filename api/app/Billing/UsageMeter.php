<?php

namespace App\Billing;

use App\Models\Tenant;
use App\Models\UsageRecord;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Append-only metering into usage_records (aggregated hourly into usage_daily) and
 * quota.threshold notifications at 80% / 100% of monthly limits.
 */
class UsageMeter
{
    /** metric → plan limit key */
    public const MONTHLY_LIMITS = [
        'searches' => 'searches_per_month',
        'render_pages' => 'render_pages_per_month',
        'provider_calls' => 'provider_calls_per_month',
    ];

    public function record(Tenant $tenant, string $metric, int $quantity = 1, ?string $referenceType = null, ?string $referenceId = null): void
    {
        $before = $this->usedThisPeriod($tenant, $metric);

        UsageRecord::acrossTenants()->create([
            'tenant_id' => $tenant->id,
            'metric' => $metric,
            'quantity' => $quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
        Cache::increment($this->counterKey($tenant, $metric), $quantity);

        $this->notifyThresholds($tenant, $metric, $before, $before + $quantity);
    }

    public function usedThisPeriod(Tenant $tenant, string $metric): int
    {
        $key = $this->counterKey($tenant, $metric);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return (int) $cached;
        }

        $used = (int) UsageRecord::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric)
            ->where('created_at', '>=', $this->periodStart())
            ->sum('quantity');
        Cache::put($key, $used, now()->endOfMonth());

        return $used;
    }

    public function periodStart(): Carbon
    {
        return now()->utc()->startOfMonth();
    }

    public function periodEnd(): Carbon
    {
        return now()->utc()->endOfMonth();
    }

    /** Roll usage_records up into usage_daily for the given day (idempotent). */
    public function aggregateDay(Carbon $day): void
    {
        $rows = DB::table('usage_records')
            ->selectRaw('tenant_id, metric, SUM(quantity) as quantity')
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->groupBy('tenant_id', 'metric')
            ->get();

        foreach ($rows as $row) {
            DB::table('usage_daily')->updateOrInsert(
                ['tenant_id' => $row->tenant_id, 'date' => $day->toDateString(), 'metric' => $row->metric],
                ['quantity' => (int) $row->quantity],
            );
        }
    }

    private function notifyThresholds(Tenant $tenant, string $metric, int $before, int $after): void
    {
        $limitKey = self::MONTHLY_LIMITS[$metric] ?? null;
        $limit = $limitKey ? $tenant->limit($limitKey) : null;
        if (! $limit) {
            return;
        }

        foreach ([80, 100] as $pct) {
            $threshold = (int) ceil($limit * $pct / 100);
            if ($before < $threshold && $after >= $threshold) {
                $dedupe = "{$tenant->cachePrefix()}:quota:{$metric}:{$this->periodStart()->format('Ym')}:{$pct}";
                if (Cache::add($dedupe, true, now()->endOfMonth())) {
                    app(WebhookDispatcher::class)->dispatch($tenant, 'quota.threshold', [
                        'metric' => $metric, 'percent' => $pct, 'used' => $after, 'limit' => $limit,
                        'period' => ['start' => $this->periodStart()->toIso8601String(), 'end' => $this->periodEnd()->toIso8601String()],
                    ]);
                }
            }
        }
    }

    private function counterKey(Tenant $tenant, string $metric): string
    {
        return "{$tenant->cachePrefix()}:usage:{$metric}:".now()->utc()->format('Ym');
    }
}
