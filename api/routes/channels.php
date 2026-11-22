<?php

use App\Models\Search;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Dashboard (Sanctum session) users. API-key clients authorize via POST /api/v1/broadcasting/auth.
Broadcast::channel('tenant.{tenantId}.search.{searchUlid}', function (User $user, int $tenantId, string $searchUlid) {
    return $user->tenants()->whereKey($tenantId)->exists()
        && Search::acrossTenants()->where('tenant_id', $tenantId)->where('ulid', $searchUlid)->exists();
});
