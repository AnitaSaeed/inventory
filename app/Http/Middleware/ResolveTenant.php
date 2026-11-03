<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $request->header('X-Tenant-Subdomain');

        if ($subdomain) {
            $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();
        } else {
            $host = $request->getHost();
            $parts = explode('.', $host);

            if (count($parts) > 2) {
                $subdomain = $parts[0];

                $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();
            } else {
                abort(404, 'Tenant not found.');
            }
        }

        app(TenantContext::class)->setTenant($tenant);

        try {
            return $next($request);
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
