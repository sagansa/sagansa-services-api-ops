<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * System roles that cannot be modified or deleted.
     */
    protected $systemRoles = ['super-admin', 'owner'];

    /**
     * Check if a role is a system role.
     */
    protected function isSystemRole($roleName)
    {
        return in_array($roleName, $this->systemRoles);
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
        $user = auth()->user();
        $tenantId = $user && !$user->hasRole('super-admin') ? $user->tenant_id : null;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'min:3',
                Rule::unique('roles')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
            'guard_name' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $user = auth()->user();
            
            // Prepare role data (force lowercase name for consistency)
            $roleData = [
                'name' => strtolower($request->name),
                'guard_name' => $request->guard_name ?? 'api',
            ];
            
            // If user is not super-admin, set tenant_id from authenticated user
            if ($user && !$user->hasRole('super-admin')) {
                $roleData['tenant_id'] = $user->tenant_id;
            }
            
            $role = Role::create($roleData);

            // Sync permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                $permissions = Permission::whereIn('name', $request->permissions)->pluck('name');
                $role->syncPermissions($permissions);
            }

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'tenant_id' => $role->tenant_id,
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
            ], 201);
        });
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
                'message' => 'Cannot modify system roles',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'min:3',
                Rule::unique('roles')->ignore($role->id)->where(function ($query) use ($role) {
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

        return DB::transaction(function () use ($role, $request) {
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
                'message' => 'Cannot delete system roles',
            ], 403);
        }

        // Check if role is assigned to any users
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role that is assigned to users',
            ], 409);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }

    /**
     * Sync permissions to a role.
     */
    public function syncPermissions(Request $request, string $id)
    {
        $role = Role::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($role, $request) {
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
