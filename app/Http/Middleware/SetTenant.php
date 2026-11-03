<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenant
{
    public function handle(
        Request $request,
        Closure $next
    )
    {
        $tenantId = $request->header('X-Tenant-ID');

        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            abort(400, 'A valid X-Tenant-ID header is required.');
        }

        $tenant = Tenant::findOrFail((int) $tenantId);

        app(TenantContext::class)->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
