<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\QuotaGuard;
use App\Billing\UsageMeter;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use Illuminate\Http\JsonResponse;

class UsageController extends Controller
{
    public function show(UsageMeter $usage, QuotaGuard $quota): JsonResponse
    {
        $tenant = $this->tenant();

        $metrics = [
            ['metric' => 'searches', 'used' => $usage->usedThisPeriod($tenant, 'searches'), 'limit' => $tenant->limit('searches_per_month')],
            ['metric' => 'render_pages', 'used' => $usage->usedThisPeriod($tenant, 'render_pages'), 'limit' => $tenant->limit('render_pages_per_month')],
            ['metric' => 'provider_calls', 'used' => $usage->usedThisPeriod($tenant, 'provider_calls'), 'limit' => $tenant->limit('provider_calls_per_month')],
            ['metric' => 'active_monitors', 'used' => Monitor::query()->where('status', 'active')->count(), 'limit' => $tenant->limit('active_monitors')],
            ['metric' => 'concurrent_searches', 'used' => $quota->activeSearches($tenant), 'limit' => $tenant->limit('concurrent_searches')],
        ];

        return response()->json(['data' => [
            'period' => ['start' => $usage->periodStart()->toIso8601ZuluString(), 'end' => $usage->periodEnd()->toIso8601ZuluString()],
            'plan' => ['code' => $tenant->plan->code, 'name' => $tenant->plan->name],
            'metrics' => $metrics,
            'limits' => $tenant->plan->limits,
            'features' => collect(['llm-extraction', 'image-search', 'monitors', 'search-engines', 'byo-proxies'])
                ->mapWithKeys(fn ($f) => [$f => $tenant->hasFeature($f)])->all(),
        ]]);
    }
}
