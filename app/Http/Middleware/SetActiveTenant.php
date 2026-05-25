<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveTenant
{
    /**
     * Handle an incoming request and set active tenant context.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return $next($request);
        }

        // Get active tenant from header (sent by frontend)
        $activeTenantId = $request->header('X-Active-Tenant');

        // Default to user's primary tenant if no header provided
        if (!$activeTenantId) {
            $activeTenantId = $user->tenant_id;
        }

        // Validate user has access to this tenant
        $hasAccess = $user->tenant_id === $activeTenantId 
            || $user->tenants()->where('tenants.id', $activeTenantId)->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this tenant',
            ], 403);
        }

        // Set active tenant in request attributes for controllers to use
        $request->merge(['active_tenant_id' => $activeTenantId]);
        
        // Also set as request attribute for easier access
        $request->attributes->set('active_tenant_id', $activeTenantId);

        return $next($request);
    }
}
