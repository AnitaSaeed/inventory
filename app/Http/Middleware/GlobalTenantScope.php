<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GlobalTenantScope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Product::addGlobalScope('tenant', function ($builder) {
            $tenantId = app(\App\Support\TenantContext::class)->current();
            return $builder->where('tenant_id', $tenantId);
        });

        StockMovement::addGlobalScope('tenant', function ($builder) {
            $tenantId = app(\App\Support\TenantContext::class)->current();
            return $builder->where('tenant_id', $tenantId);
        });

        StockLevel::addGlobalScope('tenant', function ($builder) {
            $tenantId = app(\App\Support\TenantContext::class)->current();
            return $builder->where('tenant_id', $tenantId);
        });

        Warehouse::addGlobalScope('tenant', function ($builder) {
            $tenantId = app(\App\Support\TenantContext::class)->current();
            return $builder->where('tenant_id', $tenantId);
        });

        return $next($request);
    }
}
