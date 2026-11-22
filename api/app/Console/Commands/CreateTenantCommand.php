<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admin provisioning: tenant + optional owner user + an admin API key (printed once).
 */
class CreateTenantCommand extends Command
{
    protected $signature = 'pricelens:tenant:create {name} {--plan=starter} {--slug=} {--owner-email=} {--currency=USD} {--country=US}';

    protected $description = 'Provision a tenant and print an API key';

    public function handle(): int
    {
        $plan = Plan::query()->where('code', $this->option('plan'))->first();
        if (! $plan) {
            $this->error("Unknown plan '{$this->option('plan')}'. Run php artisan db:seed --class=PlanSeeder.");

            return self::FAILURE;
        }

        $tenant = Tenant::query()->create([
            'name' => $this->argument('name'),
            'slug' => $this->option('slug') ?: Str::slug($this->argument('name')).'-'.Str::lower(Str::random(4)),
            'plan_id' => $plan->id,
            'default_currency' => strtoupper((string) $this->option('currency')),
            'default_country' => strtoupper((string) $this->option('country')),
            'retention_days' => (int) config('pricelens.defaults.retention_days'),
        ]);

        if ($email = $this->option('owner-email')) {
            $password = Str::password(20);
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => Str::before($email, '@'), 'password' => Hash::make($password)]);
            $tenant->users()->attach($user->id, ['role' => 'owner']);
            $user->update(['current_tenant_id' => $tenant->id]);
            if ($user->wasRecentlyCreated) {
                $this->line("Owner password: {$password}");
            }
        }

        [, $plaintext] = ApiKey::generate($tenant, 'Default admin key', ['admin']);

        $this->info("Tenant {$tenant->name} ({$tenant->ulid}) created on plan {$plan->code}.");
        $this->line("API key (shown once): {$plaintext}");

        return self::SUCCESS;
    }
}
