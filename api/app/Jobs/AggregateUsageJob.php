<?php

namespace App\Jobs;

use App\Billing\UsageMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Hourly: roll usage_records into usage_daily for today (and yesterday, to close the day). */
class AggregateUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(UsageMeter $usage): void
    {
        $usage->aggregateDay(now()->utc()->subDay());
        $usage->aggregateDay(now()->utc());
    }
}
