<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:mysql_auth.users,email',
            'password' => 'required|string|min:8|confirmed',
            'tenant_name' => 'required|string|max:255',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tenant_id' => null,
            'manager_id' => null,
        ]);

        $tenantName = trim((string) $request->input('tenant_name'));

        $tenant = Tenant::create([
            'name' => $tenantName,
            'owner_id' => $user->uuid,
        ]);

        $this->ensureOwnerAccess($user, $tenant);

        // Log user creation
        \Log::info('User created', ['user_id' => $user->id]);

        // Load fresh user data with roles and tenant meta
        $user->load($this->userRelations());
        $roles = $this->hydrateEffectiveRoles($user);

        \Log::info('Registration response', [
            'user_id' => $user->id,
            'roles_count' => $roles->count(),
            'roles' => $roles->toArray()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'user' => $user,
            'roles' => $roles,
        ], 201);
    }

    /**
     * Login user and create token
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // is_active check removed to allow login to owned tenant
        // if (!$user->is_active) { ... }

        // Create token for web application
        $token = $user->createToken('web-app-token');
        $plainTextToken = $token->plainTextToken;

        // Check if user is super-admin (global role) before setting tenant context
        $isSuperAdmin = \Illuminate\Support\Facades\DB::connection('mysql_auth')->table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->where('roles.name', 'super-admin')
            ->exists();

        // Load owned tenant to check if user is an owner
        $user->load('ownedTenant');

        $hasTenant = $this->ensureOwnerAccess($user);

        if (!$hasTenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant setup is required before accessing apps/ops.',
                'requires_tenant_setup' => true,
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
            ], 409);
        }

        // Only set tenant context for non-super-admin users
        if (!$isSuperAdmin && $user->tenant_id) {
            $user->setTenantById($user->tenant_id);
            // Also set global registrar just in case
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        }

        // Force reload of roles
        $user->unsetRelation('roles');
        $user->load($this->userRelations());

        // Check access-backoffice permission for each tenant membership
        foreach ($user->tenants as $tenant) {
            $user->setPermissionsTeamId($tenant->id);
            $tenant->has_backoffice_access = $user->can('access-backoffice');
        }
        
        // Reset to current tenant context
        if ($user->tenant_id) {
            $user->setPermissionsTeamId($user->tenant_id);
        }

        $roles = $this->hydrateEffectiveRoles($user);
        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'roles' => $roles,
            'permissions' => $permissions,
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        // Reload user from DB to ensure fresh status
        $user = User::with('ownedTenant')->find($request->user()->id);

        if (!$user) {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'User not found.'], 401);
        }

        // If user is inactive, they can ONLY access their owned tenant
        if (!$user->is_active) {
            $ownedTenant = $user->ownedTenant;
            
            if (!$ownedTenant) {
                // If inactive and no owned tenant, block access
                $request->user()->currentAccessToken()->delete();
                return response()->json(['message' => 'Your account has been deactivated and you do not own any tenant.'], 401);
            }
            
            // Force context to owned tenant
            $user->setTenantById($ownedTenant->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($ownedTenant->id);
            
            // Update the user object's tenant_id to reflect the forced context
            $user->tenant_id = $ownedTenant->id;
            $user->save(); // Persist the change so load() uses the correct tenant
        }

        $hasTenant = $this->ensureOwnerAccess($user);

        if (!$hasTenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant setup is required before accessing apps/ops.',
                'requires_tenant_setup' => true,
            ], 409);
        }
        
        // Check if user is super-admin (global role) before setting tenant context
        $isSuperAdmin = \Illuminate\Support\Facades\DB::connection('mysql_auth')->table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->where('roles.name', 'super-admin')
            ->exists();
        
        // Only set tenant context for non-super-admin users
        if (!$isSuperAdmin && $user->tenant_id) {
            $user->setTenantById($user->tenant_id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        }
        
        $user->unsetRelation('roles');
        $user->load($this->userRelations());
        
        // Check access-backoffice permission for each tenant membership
        foreach ($user->tenants as $tenant) {
            $user->setPermissionsTeamId($tenant->id);
            $tenant->has_backoffice_access = $user->can('access-backoffice');
        }
        
        // Reset to current tenant context
        if ($user->tenant_id) {
            $user->setPermissionsTeamId($user->tenant_id);
        }
        
        $roles = $this->hydrateEffectiveRoles($user);

        \Log::info('User endpoint debug', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'user_permissions_team_id' => $user->getPermissionsTeamId(),
            'global_permissions_team_id' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
            'roles_count' => $user->roles->count(),
            'roles_names' => $roles,
            'guard_name_method' => $user->guardName(),
            'raw_role_check' => \Illuminate\Support\Facades\DB::connection('mysql_auth')->table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->get(),
        ]);

        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => $roles,
            'debug_info' => [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'tenant_loaded' => $user->relationLoaded('tenant'),
                'tenant_owner_id' => $user->tenant ? $user->tenant->owner_id : 'null',
                'is_owner_match' => $user->tenant && $this->tenantIsOwnedByUser($user->tenant, $user),
                'roles_count' => $user->roles->count(),
                'roles_names' => $user->roles->pluck('name'),
                'is_active' => $user->is_active,
            ]
        ]);
    }

    /**
     * Validate existing token for mobile apps
     */
    public function validateToken(Request $request)
    {
        $user = $request->user();

        // Check if user is super-admin (global role) before setting tenant context
        $isSuperAdmin = \Illuminate\Support\Facades\DB::connection('mysql_auth')->table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->where('roles.name', 'super-admin')
            ->exists();

        // Only set tenant context for non-super-admin users
        if (!$isSuperAdmin && $user->tenant_id) {
            $user->setTenantById($user->tenant_id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        }
        
        $user->unsetRelation('roles');
        $user->load($this->userRelations());
        
        return response()->json([
            'success' => true,
            'message' => 'Token is valid',
            'user' => $user,
            'roles' => $this->hydrateEffectiveRoles($user),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * Default relations to eager-load for authenticated user payloads.
     *
     * @return array<int, mixed>
     */
    private function userRelations(): array
    {
        return [
            'roles:id,name',
            'tenant' => function ($query) {
                $query
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query
                    ->withPivot(['role', 'assigned_by'])
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ];
    }

    private function hydrateEffectiveRoles(User $user): \Illuminate\Support\Collection
    {
        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name');
        }

        if (!$user->relationLoaded('tenants')) {
            $user->load('tenants:id,name');
        }

        $roles = $user->roles->pluck('name');
        $activeTenantId = $user->tenant_id;

        foreach ($user->tenants as $tenant) {
            if ($activeTenantId !== null && (string) $tenant->id !== (string) $activeTenantId) {
                continue;
            }

            $pivotRole = $tenant->pivot?->role;

            if ($pivotRole) {
                $roles->push($pivotRole);
            }
        }

        if ($user->tenant && $this->tenantIsOwnedByUser($user->tenant, $user)) {
            $roles->push('owner');
        }

        $roles = $roles
            ->filter()
            ->unique()
            ->values();

        $existingRoleNames = $user->roles->pluck('name');

        foreach ($roles as $roleName) {
            if ($existingRoleNames->contains($roleName)) {
                continue;
            }

            $role = new \Spatie\Permission\Models\Role();
            $role->id = 'effective-' . $roleName;
            $role->name = $roleName;
            $role->guard_name = 'api';

            $user->roles->push($role);
        }

        return $roles;
    }

    private function ensureOwnerAccess(User $user, ?Tenant $tenant = null): bool
    {
        $tenant ??= $user->ownedTenant;

        if (!$tenant && $user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);
        }

        if (!$tenant) {
            $ownerMembership = $user->tenants()
                ->wherePivot('role', 'owner')
                ->first();

            if ($ownerMembership) {
                $tenant = $ownerMembership;
            }
        }

        if (!$tenant) {
            $tenant = $user->tenants()->first();

            if ($tenant && (string) $user->tenant_id !== (string) $tenant->id) {
                $user->tenant_id = $tenant->id;
                $user->save();
            }
        }

        if (!$tenant) {
            return false;
        }

        DB::connection('mysql_ops')->transaction(function () use ($user, $tenant) {
            if ((string) $tenant->owner_id !== (string) $user->uuid) {
                $tenant->owner_id = $user->uuid;
                $tenant->save();
            }

            if ((string) $user->tenant_id !== (string) $tenant->id) {
                $user->tenant_id = $tenant->id;
                $user->save();
            }

            DB::connection('mysql_ops')
                ->table('tenant_user')
                ->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->uuid,
                    ],
                    [
                        'role' => 'owner',
                        'assigned_by' => $user->uuid,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
        });

        try {
            $this->ensureOwnerRole($tenant->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $user->assignRoleInTenant('owner', $tenant->id);
        } catch (\Throwable $e) {
            \Log::warning('Unable to assign Spatie owner role during ops owner sync', [
                'user_id' => $user->id,
                'user_uuid' => $user->uuid,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        $user->refresh();
        $user->load('ownedTenant');

        return true;
    }

    private function ensureOwnerRole(string $tenantId): void
    {
        $permissions = [
            'access-backoffice',
            'access-pos',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'api',
                'tenant_id' => $tenantId,
            ]);
        }

        $role = Role::firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'api',
            'tenant_id' => $tenantId,
        ]);

        $role->syncPermissions($permissions);
    }

    private function tenantIsOwnedByUser(Tenant $tenant, User $user): bool
    {
        return in_array((string) $tenant->owner_id, array_filter([
            (string) $user->uuid,
            (string) $user->id,
        ]), true);
    }

    /**
     * Retrieve invitation details without authentication.
     */
    public function showInvitation(string $token)
    {
        $user = User::with(['tenants:id,name'])
            ->where('invitation_token', $token)
            ->where(function ($query) {
                $query->whereNull('invitation_token_expires_at')
                    ->orWhere('invitation_token_expires_at', '>=', CarbonImmutable::now());
            })
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Undangan tidak ditemukan atau sudah kedaluwarsa.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'invitation' => [
                'email' => $user->email,
                'tenant_names' => $user->tenants->pluck('name')->values(),
            ],
        ]);
    }

    /**
     * Complete invitation by setting user profile and password.
     */
    public function completeInvitation(Request $request, string $token)
    {
        $user = User::with(['tenants:id,name'])
            ->where('invitation_token', $token)
            ->where(function ($query) {
                $query->whereNull('invitation_token_expires_at')
                    ->orWhere('invitation_token_expires_at', '>=', CarbonImmutable::now());
            })
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Undangan tidak ditemukan atau sudah kedaluwarsa.',
            ], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => CarbonImmutable::now(),
            'invitation_token' => null,
            'invitation_token_expires_at' => null,
            'invited_at' => $user->invited_at ?? CarbonImmutable::now(),
        ]);

        if ($user->tenant_id === null && $user->tenants->isNotEmpty()) {
            $user->tenant_id = $user->tenants->first()->id;
        }

        $user->save();

        // Create token for web application
        $tokenResult = $user->createToken('web-app-token');
        $token = $tokenResult->plainTextToken;
        $user->load($this->userRelations());

        return response()->json([
            'success' => true,
            'message' => 'Undangan berhasil diselesaikan.',
            'user' => $user,
            'roles' => $this->hydrateEffectiveRoles($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
