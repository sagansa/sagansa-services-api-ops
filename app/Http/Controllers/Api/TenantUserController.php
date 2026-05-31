<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantUserController extends Controller
{
    /**
     * List all users in a tenant with their roles and permissions.
     */
    public function index(Request $request, string $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $memberships = DB::connection('mysql_ops')
            ->table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('user_id');

        // Get permissions and roles for each user in this tenant
        $users = User::query()
            ->whereIn('uuid', $memberships->keys()->all())
            ->get()
            ->map(function ($user) use ($tenantId, $memberships) {
            $membership = $memberships->get($user->uuid);

            return [
                'id' => $user->uuid ?: $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'pivot_role' => $membership->role ?? null,
                'assigned_by' => $membership->assigned_by ?? null,
                'joined_at' => $membership->created_at ?? null,
                'roles' => $user->getRolesInTenant($tenantId)->pluck('name'),
                'permissions' => $user->getPermissionsInTenant($tenantId)->pluck('name'),
            ];
        });

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'users' => $users,
        ]);
    }

    /**
     * Search available users to add to tenant
     */
    public function searchAvailableUsers(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        
        // Check if current user has permission to manage users in this tenant
        if (!$user->hasPermissionInTenant('access-backoffice', $tenantId)) {
            return response()->json([
                'message' => 'Unauthorized. You need access-backoffice permission.'
            ], 403);
        }

        $search = $request->query('search', '');
        
        // Get users matching search, excluding current tenant members and super-admins
        Tenant::findOrFail($tenantId);
        $existingUserUuids = DB::connection('mysql_ops')
            ->table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->pluck('user_id');
        
        $query = User::query()
            ->whereNotIn('uuid', $existingUserUuids)
            ->whereNotExists(function ($query) {
                $query->select(\Illuminate\Support\Facades\DB::raw(1))
                      ->from('model_has_roles')
                      ->whereColumn('model_has_roles.model_id', 'users.id')
                      ->where('model_type', User::class)
                      ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                      ->where('roles.name', 'super-admin');
            });
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }
        
        $users = $query->limit(20)->get();
        
        return response()->json([
            'success' => true,
            'users' => $users->map(function ($user) {
                return [
                    'id' => $user->uuid ?: $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            }),
        ]);
    }

    /**
     * Add existing user to tenant with role.
     */
    public function addUser(Request $request, string $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->ensureDefaultTenantRoles($tenantId);
        
        // Check if current user has permission to manage users in this tenant
        if (!$request->user()->hasPermissionInTenant('access-backoffice', $tenantId)) {
            return response()->json([
                'message' => 'Unauthorized. You need access-backoffice permission.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'role' => [
                'required',
                'string',
                Rule::in(array_keys($this->defaultTenantRolePermissions())),
                Rule::exists('mysql_auth.roles', 'name')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return $this->inviteUser($request, $tenantId, $request->email, $request->role);
    }

    /**
     * Invite a new user to tenant via email
     */
    private function inviteUser(Request $request, string $tenantId, string $email, string $role)
    {
        return DB::transaction(function () use ($request, $tenantId, $email, $role) {
            $tenant = Tenant::findOrFail($tenantId);
            $invitationToken = Str::random(60);

            $user = User::where('email', $email)->first();

            if ($user) {
                $user->forceFill([
                    'invitation_token' => $invitationToken,
                    'invitation_token_expires_at' => now()->addDays(7),
                    'invited_at' => now(),
                    'invited_by' => $request->user()->uuid,
                ])->save();
            } else {
                $user = User::create([
                    'name' => $email,
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                    'invitation_token' => $invitationToken,
                    'invitation_token_expires_at' => now()->addDays(7),
                    'invited_at' => now(),
                    'invited_by' => $request->user()->uuid,
                    'email_verified_at' => null,
                ]);
            }
            
            DB::connection('mysql_ops')
                ->table('tenant_user')
                ->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->uuid,
                    ],
                    [
                    'role' => $role,
                    'assigned_by' => $request->user()->uuid,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

            try {
                $user->assignRoleInTenant($role, $tenant->id);
            } catch (\Throwable $e) {
                \Log::warning('Unable to assign Spatie role during invitation', [
                    'email' => $email,
                    'tenant_id' => $tenant->id,
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Send invitation email
            try {
                \Mail::to($email)->send(new \App\Mail\UserInvitation($user, $tenant, $invitationToken));
            } catch (\Exception $e) {
                \Log::error('Failed to send invitation email: ' . $e->getMessage());
            }

            $invitationUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/')
                . '/accept-invite?token=' . $invitationToken;
            
            return response()->json([
                'message' => 'Invitation sent successfully',
                'email' => $email,
                'invitation_url' => $invitationUrl,
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ],
                'role' => $role,
            ], 201);
        });
    }

    /**
     * Update user's role in tenant.
     */
    public function updateRole(Request $request, string $tenantId, string $userId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $user = $this->findUserByKey($userId);
        $this->ensureDefaultTenantRoles($tenantId);

        if (!$request->user()->hasPermissionInTenant('access-backoffice', $tenantId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'role' => [
                'required',
                'string',
                Rule::in(array_keys($this->defaultTenantRolePermissions())),
                Rule::exists('mysql_auth.roles', 'name')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Cannot change owner's role
        if ($tenant->owner_id === ($user->uuid ?: $user->id)) {
            return response()->json([
                'message' => 'Cannot change tenant owner role'
            ], 403);
        }

        // Cannot change own role
        if (($request->user()->uuid ?: $request->user()->id) === ($user->uuid ?: $user->id)) {
            return response()->json([
                'message' => 'You cannot change your own role'
            ], 403);
        }

        return DB::transaction(function () use ($tenant, $user, $request) {
            // Get current roles
            $currentRoles = $user->getRolesInTenant($tenant->id)->pluck('name')->toArray();

            // Remove all current roles in this tenant
            foreach ($currentRoles as $roleName) {
                $user->removeRoleInTenant($roleName, $tenant->id);
            }

            // Assign new role
            $user->assignRoleInTenant($request->role, $tenant->id);

            DB::connection('mysql_ops')
                ->table('tenant_user')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->uuid)
                ->update([
                    'role' => $request->role,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Role updated successfully',
                'user' => [
                    'id' => $user->uuid ?: $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'role' => $request->role,
                ],
            ]);
        });
    }

    /**
     * Assign permissions to user in tenant.
     */
    public function assignPermissions(Request $request, string $tenantId, string $userId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $user = $this->findUserByKey($userId);

        if (!$request->user()->hasPermissionInTenant('access-backoffice', $tenantId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:mysql_auth.permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($user, $tenant, $request) {
            // Sync permissions (replaces all existing permissions)
            $user->setTenantById($tenant->id);
            $user->syncPermissions($request->permissions);

            return response()->json([
                'message' => 'Permissions updated successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'permissions' => $request->permissions,
                ],
            ]);
        });
    }

    /**
     * Get all available permissions grouped by module.
     */
    public function getPermissions()
    {
        $permissions = DB::connection('mysql_auth')
            ->table('permissions')
            ->where('guard_name', 'api')
            ->orderBy('name')
            ->get();

        // Group by module (prefix before dot)
        $grouped = $permissions->groupBy(function ($permission) {
            $parts = explode('.', $permission->name);
            return $parts[0] ?? 'other';
        })->map(function ($group, $module) {
            return [
                'module' => ucfirst($module),
                'permissions' => $group->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'label' => ucwords(str_replace(['.', '-'], [' ', ' '], $permission->name)),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'permissions' => $grouped,
        ]);
    }

    /**
     * Get all available roles with full details.
     */
    public function getRoles()
    {
        $user = auth()->user();

        if (!$user || !$user->hasRole('super-admin')) {
            $this->ensureDefaultTenantRoles($user?->tenant_id);

            $query = Role::with(['permissions', 'tenant'])
                ->where('tenant_id', $user?->tenant_id)
                ->whereIn('name', array_keys($this->defaultTenantRolePermissions()));
        } else {
            $query = Role::with(['permissions', 'tenant'])
                ->whereIn('name', array_keys($this->defaultTenantRolePermissions()));
        }
        
        $roles = $query->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'tenant_id' => $role->tenant_id,
                'label' => ucwords(str_replace('-', ' ', $role->name)),
                'permissions_count' => $role->permissions->count(),
                'created_at' => $role->created_at->toISOString(),
                'updated_at' => $role->updated_at->toISOString(),
                'tenant' => $role->tenant ? [
                    'id' => $role->tenant->id,
                    'name' => $role->tenant->name,
                ] : null,
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

    private function ensureDefaultTenantRoles(?string $tenantId): void
    {
        if (!$tenantId) {
            return;
        }

        foreach ($this->defaultTenantRolePermissions() as $roleName => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'api',
                    'tenant_id' => $tenantId,
                ]);
            }

            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api',
                'tenant_id' => $tenantId,
            ]);

            $role->syncPermissions($permissionNames);
        }
    }

    private function defaultTenantRolePermissions(): array
    {
        return [
            'manager' => ['access-backoffice', 'access-pos'],
            'kasir' => ['access-pos'],
            'support' => ['access-backoffice'],
        ];
    }

    /**
     * Remove user from tenant.
     */
    public function removeUser(Request $request, string $tenantId, string $userId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $user = $this->findUserByKey($userId);

        if (!$request->user()->hasPermissionInTenant('access-backoffice', $tenantId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Cannot remove tenant owner
        if ($tenant->owner_id === ($user->uuid ?: $user->id)) {
            return response()->json([
                'message' => 'Cannot remove tenant owner'
            ], 403);
        }

        // Cannot remove yourself
        if (($request->user()->uuid ?: $request->user()->id) === ($user->uuid ?: $user->id)) {
            return response()->json([
                'message' => 'You cannot remove yourself from the tenant'
            ], 403);
        }

        return DB::transaction(function () use ($tenant, $user) {
            // Get user's data before removing
            $pivotData = DB::connection('mysql_ops')
                ->table('tenant_user')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->uuid)
                ->first();
            
            if (!$pivotData) {
                return response()->json([
                    'message' => 'User not found in tenant'
                ], 404);
            }

            // Get roles and permissions in this tenant
            $roles = $user->getRolesInTenant($tenant->id)->pluck('name')->toArray();
            $permissions = $user->getPermissionsInTenant($tenant->id)->pluck('name')->toArray();

            // Remove all roles in this tenant
            foreach ($roles as $roleName) {
                $user->removeRoleInTenant($roleName, $tenant->id);
            }

            // Remove all permissions in this tenant
            foreach ($permissions as $permissionName) {
                $user->revokePermissionInTenant($permissionName, $tenant->id);
            }

            // Remove from pivot
            DB::connection('mysql_ops')
                ->table('tenant_user')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->uuid)
                ->delete();

            return response()->json([
                'message' => 'User removed from tenant successfully'
            ]);
        });
    }

    private function findUserByKey(string $key): User
    {
        return User::query()
            ->where('uuid', $key)
            ->orWhere('id', $key)
            ->firstOrFail();
    }
}
