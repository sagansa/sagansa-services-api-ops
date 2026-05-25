<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if ($user) {
            // Get tenant from: 1) Active Tenant Header, 2) X-Tenant-ID Header, 3) Route parameter, 4) User's default tenant
            $tenantId = $request->header('X-Active-Tenant') 
                     ?? $request->header('X-Tenant-ID') 
                     ?? $request->route('tenant_id')
                     ?? $request->route('tenantId')
                     ?? $user->tenant_id;

            if ($tenantId) {
                // Strict validation: Does user have access to this tenant?
                // Check if it's their own tenant OR if they have a relationship in tenant_user table
                $hasAccess = $user->tenant_id === $tenantId 
                    || $user->tenants()->where('tenants.id', $tenantId)->exists();

                if (!$hasAccess && !$user->hasRole('super-admin')) { // Allow super-admin to access any tenant if needed
                    return response()->json([
                        'message' => 'You do not have access to this tenant.',
                    ], 403);
                }

                $tenant = \App\Models\Tenant::find($tenantId);
                
                if ($tenant) {
                    // If user is inactive, ensure they own the tenant
                    if (!$user->is_active && $tenant->owner_id !== $user->id) {
                        return response()->json([
                            'message' => 'Your account is inactive. You can only access your owned tenant.',
                        ], 403);
                    }

                    // Set tenant context for permission checks
                    $user->setTenant($tenant);
                    
                    // Also set global registrar just in case
                    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
                    
                    // Force reload of roles and permissions with new context
                    $user->unsetRelation('roles');
                    $user->unsetRelation('permissions');
                    
                    // Store in request for easy access
                    $request->attributes->set('current_tenant', $tenant);
                    $request->attributes->set('current_tenant_id', $tenant->id);
                }
            }
        }

        return $next($request);
    }
}
