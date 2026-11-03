<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $tenantId = $request->header('X-Tenant-Id')
            ?? $request->header('X-Tenant-Subdomain')
            ?? $this->resolveFromSubdomain($request);

        if ($tenantId === null) {
            abort(401, 'Tenant identifier missing');
        }

        // Only allow from authenticated requests or trusted proxy
        if (!$request->user() && !$this->isTrustedProxy($request)) {
            abort(401, 'Unauthenticated tenant resolution');
        }

        app(\App\Support\TenantContext::class)->set($tenantId);

        return $next($request);
    }
    protected function resolveFromSubdomain(Request $request): ?string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);
        return $parts[0] !== 'www' && count($parts) > 1 ? $parts[0] : null;
    }
}
