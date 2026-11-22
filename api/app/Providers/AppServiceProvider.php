<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\ApiKey;
use App\Models\Monitor;
use App\Models\Offer;
use App\Models\Search;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TrackedProduct;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Observability\Tracing;
use App\Policies\SourcePolicy;
use App\Policies\TenantOwnedPolicy;
use App\Scraping\Fetching\SafeUrlGuard;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class AppServiceProvider extends ServiceProvider
{
    /** Pennant flags, resolved per tenant from plan features + tenant overrides. */
    public const FEATURES = ['llm-extraction', 'image-search', 'monitors', 'search-engines', 'byo-proxies'];

    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs.
        $this->app->scoped(CurrentTenant::class);
        $this->app->singleton(SafeUrlGuard::class);
        $this->app->singleton(Tracing::class);
    }

    public function boot(): void
    {
        // Flush spans when long-running workers idle/stop and at the end of CLI commands.
        Queue::looping(fn () => app(Tracing::class)->flush());
        $this->app->terminating(fn () => app(Tracing::class)->shutdown());

        foreach ([Search::class, Offer::class, TrackedProduct::class, Monitor::class, Alert::class,
            WebhookEndpoint::class, WebhookDelivery::class, ApiKey::class] as $model) {
            Gate::policy($model, TenantOwnedPolicy::class);
        }
        Gate::policy(Source::class, SourcePolicy::class);

        foreach (self::FEATURES as $feature) {
            Feature::define($feature, fn (mixed $scope) => $scope instanceof Tenant && $scope->hasFeature($feature));
        }

        RateLimiter::for('api', function (Request $request) {
            $current = CurrentTenant::instance();
            if (! $current->check()) {
                return Limit::perMinute(60)->by('ip:'.$request->ip());
            }
            $tenant = $current->get();
            $rpm = $tenant->limit('api_rpm') ?? 600;
            $limits = [Limit::perMinute($rpm)->by("tenant:{$tenant->id}")];
            if ($key = $request->attributes->get('api_key')) {
                $limits[] = Limit::perMinute((int) ($tenant->setting('api_key_rpm') ?? $rpm))->by("key:{$key->id}");
            }

            return $limits;
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth-email:'.strtolower((string) $request->input('email'))),
        ]);

        // Password reset links point at the UI (no server-rendered Laravel pages).
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('pricelens.auth.ui_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        RateLimiter::for('source-test', fn (Request $request) => Limit::perMinute(30)
            ->by('source-test:'.(CurrentTenant::instance()->check() ? CurrentTenant::instance()->id() : $request->ip())));
    }
}
