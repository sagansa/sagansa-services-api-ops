<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwitchTenantController extends Controller
{
    /**
     * Switch the user's active tenant.
     */
    public function switchTenant(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|uuid|exists:mysql_ops.tenants,id',
        ]);

        $user = $request->user();
        $targetTenantId = $request->tenant_id;
        $targetTenant = Tenant::find($targetTenantId);

        // Verify that the user has access to this tenant
        $hasTenantAccess = $user->tenants()->where('tenants.id', $targetTenantId)->exists();
        $ownsTenant = $targetTenant && in_array((string) $targetTenant->owner_id, array_filter([
            (string) $user->uuid,
            (string) $user->id,
        ]), true);

        if (!$hasTenantAccess && !$ownsTenant) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this tenant',
            ], 403);
        }

        // If user is inactive, they can ONLY switch to their owned tenant
        if (!$user->is_active) {
            if (!$ownsTenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is inactive. You can only access your owned tenant.',
                ], 403);
            }
        }

        // Update the user's active tenant_id
        $user->tenant_id = $targetTenantId;
        $user->save();

        // Check if user is super-admin before setting tenant context
        $isSuperAdmin = DB::connection('mysql_auth')->table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->where('roles.name', 'super-admin')
            ->exists();

        // Set tenant context for non-super-admin users
        if (!$isSuperAdmin) {
            $user->setTenantById($targetTenantId);
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($targetTenantId);
        }

        // Reload user with fresh data
        $user->unsetRelation('roles');
        $user->unsetRelation('tenant');
        $user->unsetRelation('tenants');
        $user->load([
            'roles:id,name',
            'tenant' => function ($query) {
                $query
                    ->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query
                    ->withPivot(['role', 'assigned_by'])
                    ->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant switched successfully',
            'user' => $user,
            'roles' => $user->getRoleNames(),
        ]);
    }
}
