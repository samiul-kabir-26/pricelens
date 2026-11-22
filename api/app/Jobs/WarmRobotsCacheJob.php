<?php

namespace App\Jobs;

use App\Models\Source;
use App\Scraping\Fetching\RobotsPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Pre-fetch robots.txt for every enabled site source domain so pipeline fetches hit the cache. */
class WarmRobotsCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function handle(RobotsPolicy $robots): void
    {
        Source::acrossTenants()->where('type', 'site')->where('enabled', true)->whereNotNull('domain')
            ->distinct()->pluck('domain')
            ->each(function (string $domain) use ($robots) {
                try {
                    $robots->content('https://'.$domain, (string) config('pricelens.scraper.user_agent'));
                } catch (Throwable) {
                    // unreachable hosts are cached as such by RobotsPolicy
                }
            });
    }
}
