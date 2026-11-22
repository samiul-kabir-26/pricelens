<?php

use App\Jobs\AggregateUsageJob;
use App\Jobs\DispatchDueMonitorsJob;
use App\Jobs\MaintainSnapshotPartitionsJob;
use App\Jobs\PurgeExpiredDataJob;
use App\Jobs\RefreshFeedJob;
use App\Jobs\RefreshFxRatesJob;
use App\Jobs\RefreshSitemapJob;
use App\Jobs\RollupPriceDailyJob;
use App\Jobs\WarmRobotsCacheJob;
use App\Models\Source;
use App\Services\SourceHealthRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// The scheduler container runs exactly once (`schedule:work`); onOneServer() guards accidental duplicates.
Schedule::job(new DispatchDueMonitorsJob, 'orchestration')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::job(new RefreshFxRatesJob, 'default')->dailyAt('15:30')->timezone('UTC')->onOneServer(); // ECB publishes ~16:00 CET
Schedule::job(new AggregateUsageJob, 'default')->hourly()->onOneServer();
Schedule::job(new RollupPriceDailyJob, 'default')->dailyAt('00:30')->onOneServer();
Schedule::job(new PurgeExpiredDataJob, 'default')->dailyAt('03:00')->onOneServer();
Schedule::job(new WarmRobotsCacheJob, 'default')->dailyAt('04:00')->onOneServer();
Schedule::job(new MaintainSnapshotPartitionsJob, 'default')->dailyAt('02:30')->onOneServer();

Schedule::call(function () {
    Source::acrossTenants()->where('type', 'feed')->where('enabled', true)->whereNotNull('tenant_id')->get()
        ->filter(fn (Source $s) => ! $s->feed_refreshed_at || $s->feed_refreshed_at->addMinutes((int) $s->cfg('refresh_interval', 1440))->isPast())
        ->each(function (Source $s) {
            if ($s->tenant_id !== null) {
                RefreshFeedJob::dispatch($s->tenant_id, $s->id)->onQueue('default');
            }
        });
})->name('refresh-feeds')->everyFifteenMinutes()->onOneServer();

Schedule::call(function () {
    Source::acrossTenants()->where('type', 'site')->where('enabled', true)->get()
        ->filter(fn (Source $s) => $s->cfg('sitemap_urls') && (! $s->sitemap_refreshed_at || $s->sitemap_refreshed_at->addHours((int) config('pricelens.sitemaps.refresh_hours', 24))->isPast()))
        ->each(fn (Source $s) => RefreshSitemapJob::dispatch($s->id)->onQueue('default'));
})->name('refresh-sitemaps')->hourly()->onOneServer();

Schedule::call(function () {
    Source::acrossTenants()->whereIn('type', ['site'])->each(fn (Source $s) => app(SourceHealthRecorder::class)->evaluate($s));
})->name('source-health')->hourly()->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Artisan::command('pricelens:fx:refresh', function () {
    (new RefreshFxRatesJob)->handle();
    $this->info('ECB reference rates loaded.');
})->purpose('Load the latest ECB euro reference rates into fx_rates');

Artisan::command('pricelens:partitions', function () {
    (new MaintainSnapshotPartitionsJob)->handle();
    $this->info('price_snapshots partitions are up to date.');
})->purpose('Create upcoming monthly price_snapshots partitions (MySQL)');
