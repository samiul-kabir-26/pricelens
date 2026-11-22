<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'starter' => ['Starter', [
                'searches_per_month' => 1000, 'concurrent_searches' => 3, 'max_sources_per_search' => 10, 'max_results_per_search' => 100,
                'active_monitors' => 25, 'render_pages_per_month' => 500, 'provider_calls_per_month' => 1000, 'api_rpm' => 120, 'concurrent_tasks' => 20,
            ], ['llm-extraction' => false, 'image-search' => false, 'monitors' => true, 'search-engines' => true, 'byo-proxies' => false]],
            'pro' => ['Pro', [
                'searches_per_month' => 20000, 'concurrent_searches' => 20, 'max_sources_per_search' => 50, 'max_results_per_search' => 250,
                'active_monitors' => 1000, 'render_pages_per_month' => 20000, 'provider_calls_per_month' => 20000, 'api_rpm' => 600, 'concurrent_tasks' => 100,
            ], ['llm-extraction' => true, 'image-search' => true, 'monitors' => true, 'search-engines' => true, 'byo-proxies' => true]],
            'enterprise' => ['Enterprise', [
                'searches_per_month' => null, 'concurrent_searches' => 100, 'max_sources_per_search' => 100, 'max_results_per_search' => 500,
                'active_monitors' => null, 'render_pages_per_month' => null, 'provider_calls_per_month' => null, 'api_rpm' => 3000, 'concurrent_tasks' => 400,
            ], ['llm-extraction' => true, 'image-search' => true, 'monitors' => true, 'search-engines' => true, 'byo-proxies' => true]],
        ];

        foreach ($plans as $code => [$name, $limits, $features]) {
            Plan::query()->updateOrCreate(['code' => $code], ['name' => $name, 'limits' => $limits, 'features' => $features]);
        }
    }
}
