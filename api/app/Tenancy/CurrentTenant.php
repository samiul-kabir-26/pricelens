<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Request/job-scoped holder for the resolved tenant. Bound as a singleton (scoped) in the container.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantException('No tenant has been resolved for this context.');
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    /**
     * Run a callback as the given tenant, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }

    public static function instance(): self
    {
        return app(self::class);
    }
}
