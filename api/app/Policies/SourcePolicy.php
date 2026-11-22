<?php

namespace App\Policies;

use App\Models\Source;
use App\Models\User;
use App\Tenancy\CurrentTenant;

class SourcePolicy
{
    public function view(?User $user, Source $source): bool
    {
        return $source->is_global || $this->owns($source);
    }

    /** Global adapters are read-only for tenants (they may toggle opt-in or fork). */
    public function update(?User $user, Source $source): bool
    {
        return $this->owns($source);
    }

    public function delete(?User $user, Source $source): bool
    {
        return $this->owns($source);
    }

    public function toggle(?User $user, Source $source): bool
    {
        return $source->is_global || $this->owns($source);
    }

    private function owns(Source $source): bool
    {
        $current = CurrentTenant::instance();

        return $current->check() && ! $source->is_global && $source->tenant_id === $current->id();
    }
}
