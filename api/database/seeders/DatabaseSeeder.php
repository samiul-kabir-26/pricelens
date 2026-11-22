<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, GlobalAdapterSeeder::class]);

        if (! app()->isProduction() && ! Tenant::query()->where('slug', 'demo')->exists()) {
            $tenant = Tenant::query()->create([
                'name' => 'Demo Tenant', 'slug' => 'demo', 'plan_id' => Plan::query()->where('code', 'pro')->value('id'),
                'default_country' => 'US', 'default_currency' => 'USD', 'retention_days' => 90,
            ]);
            $user = User::query()->firstOrCreate(['email' => 'demo@pricelens.test'], ['name' => 'Demo', 'password' => Hash::make('password')]);
            $tenant->users()->attach($user->id, ['role' => 'owner']);
            $user->update(['current_tenant_id' => $tenant->id]);

            [, $key] = ApiKey::generate($tenant, 'Demo admin key', ['admin']);
            $this->command?->info("Demo tenant API key (shown once): {$key}");
        }
    }
}
