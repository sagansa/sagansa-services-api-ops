<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * Supports multiple roles separated by "|" (e.g. role:manager|owner).
     * The owner of the active tenant always passes, even without an explicit
     * role assignment.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $roles = array_filter(array_map('trim', explode('|', $role)));

        $hasRole = false;
        if (method_exists($user, 'hasRole')) {
            foreach ($roles as $single) {
                if ($user->hasRole($single)) {
                    $hasRole = true;
                    break;
                }
            }
        }

        // Owner of the active tenant always has access.
        if (!$hasRole) {
            $tenantId = $request->attributes->get('active_tenant_id') ?? $user->tenant_id;
            if ($tenantId && method_exists($user, 'ownedTenant')) {
                $hasRole = $user->ownedTenant()->where('id', $tenantId)->exists();
            }
        }

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Insufficient role permissions',
            ], 403);
        }

        return $next($request);
    }
}