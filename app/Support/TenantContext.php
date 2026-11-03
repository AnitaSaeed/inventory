<?php

namespace App\Support;

use Illuminate\Contracts\Container\Container;

class TenantContext
{
    protected ?string $tenantId = null;

    public function __construct(protected Container $container) {}

public function set(string $tenantId): void
{
    $this->tenantId = $tenantId;
}

public function current(): string
{
    if ($this->tenantId === null) {
        throw new \RuntimeException('Tenant context is not set. Middleware must run first.');
    }
    return $this->tenantId;
}

public function clear(): void
{
    $this->tenantId = null;
}
}
