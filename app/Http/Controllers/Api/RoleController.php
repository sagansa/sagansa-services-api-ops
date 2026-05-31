<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    /**
     * System roles that cannot be modified or deleted.
     */
    protected $systemRoles = ['super-admin', 'owner'];

    /**
     * Tenant roles managed by the system.
     */
    protected $managedTenantRoles = ['manager', 'kasir', 'support'];

    /**
     * Check if a role is a system role.
     */
    protected function isSystemRole($roleName)
    {
        return in_array($roleName, $this->systemRoles);
    }

    /**
     * Check if a role is generated and maintained by the system.
     */
    protected function isManagedTenantRole($role): bool
    {
        return !empty($role->tenant_id) && in_array($role->name, $this->managedTenantRoles);
    }

    /**
     * Check if current user can manage a role.
     */
    protected function canManageRole($role)
    {
        $user = auth()->user();
        
        if (!$user) {
            return false;
        }
        
        if ($this->isManagedTenantRole($role)) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }
        
        return !$this->isSystemRole($role->name);
    }

    /**
     * Display a listing of roles with permissions count.
     */
    public function index()
    {
        $user = auth()->user();
        $query = Role::with('permissions');
        
        // If not super-admin, exclude system roles
        if (!$user || !$user->hasRole('super-admin')) {
            $query->whereNotIn('name', $this->systemRoles);
        }
        
        $roles = $query->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'created_at' => $role->created_at->toISOString(),
                'updated_at' => $role->updated_at->toISOString(),
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'roles' => $roles,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Roles are managed automatically',
        ], 403);
    }

    /**
     * Display the specified role with its permissions.
     */
    public function show(string $id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        
        // Check if user can access this role
        if (!$this->canManageRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this role',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'created_at' => $role->created_at->toISOString(),
                'updated_at' => $role->updated_at->toISOString(),
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, string $id)
    {
        $role = Role::findOrFail($id);

        // Check if user can manage this role
        if (!$this->canManageRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify system-managed roles',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'min:3',
                Rule::unique('mysql_auth.roles', 'name')->ignore($role->id)->where(function ($query) use ($role) {
                    return $query->where('tenant_id', $role->tenant_id);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::connection('mysql_auth')->transaction(function () use ($role, $request) {
            // Update name if provided (force lowercase)
            if ($request->filled('name')) {
                $role->name = strtolower($request->name);
                $role->save();
            }

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'created_at' => $role->created_at->toISOString(),
                    'updated_at' => $role->updated_at->toISOString(),
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'guard_name' => $permission->guard_name,
                        ];
                    }),
                ],
            ]);
        });
    }

    /**
     * Remove the specified role.
     */
    public function destroy(string $id)
    {
        $role = Role::findOrFail($id);

        // Check if user can manage this role
        if (!$this->canManageRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system-managed roles',
            ], 403);
        }

        return DB::connection('mysql_auth')->transaction(function () use ($role) {
            $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
            $roleHasPermissionsTable = config('permission.table_names.role_has_permissions', 'role_has_permissions');
            $teamForeignKey = config('permission.column_names.team_foreign_key', 'tenant_id');

            $assignmentQuery = DB::connection('mysql_auth')
                ->table($modelHasRolesTable)
                ->where('role_id', $role->id);

            $assignedUsersCount = $role->tenant_id
                ? DB::connection('mysql_ops')
                    ->table('tenant_user')
                    ->where('tenant_id', $role->tenant_id)
                    ->where('role', $role->name)
                    ->count()
                : (clone $assignmentQuery)->count();

            if ($assignedUsersCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete role that is assigned to users',
                    'assigned_users_count' => $assignedUsersCount,
                ], 409);
            }

            if ($role->tenant_id) {
                (clone $assignmentQuery)->delete();
            }

            DB::connection('mysql_auth')
                ->table($roleHasPermissionsTable)
                ->where('role_id', $role->id)
                ->delete();

            $role->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully',
            ]);
        });
    }

    /**
     * Sync permissions to a role.
     */
    public function syncPermissions(Request $request, string $id)
    {
        $role = Role::findOrFail($id);

        if (!$this->canManageRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify system-managed roles',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => ['string', Rule::exists('mysql_auth.permissions', 'name')],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::connection('mysql_auth')->transaction(function () use ($role, $request) {
            $role->syncPermissions($request->permissions);
            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Permissions synced successfully',
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'guard_name' => $permission->guard_name,
                        ];
                    }),
                ],
            ]);
        });
    }
}
