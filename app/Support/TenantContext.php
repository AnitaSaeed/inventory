<?php

namespace App\Support;

use App\Models\Tenant;

class TenantContext
{
    protected ?Tenant $tenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantId(): ?int
    {
        return $this->tenant->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
