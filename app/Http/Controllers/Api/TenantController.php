<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing(['tenant', 'tenants', 'ownedTenant']);

        // If super-admin, return all tenants
        if ($user->hasRole('super-admin')) {
            $tenants = Tenant::with(['owner', 'stores'])->withCount('users')->get();
            return response()->json([
                'success' => true,
                'tenants' => $tenants
            ]);
        }

        $allTenants = collect();

        if ($user->tenant) {
            $allTenants->push($user->tenant);
        }

        $allTenants = $allTenants
            ->merge($user->tenants)
            ->merge(Tenant::whereIn('owner_id', $this->userOwnerKeys($user))->get())
            ->unique('id')
            ->values();

        $allTenants->each(function (Tenant $tenant) {
            $tenant->load(['owner', 'stores']);
            $tenant->loadCount('users');
        });

        return response()->json([
            'success' => true,
            'tenants' => $allTenants
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'owner_id' => 'nullable|string',
            'operation_mode' => 'nullable|in:standard,foodcourt',
        ]);

        // Default owner to authenticated user if not provided
        $owner = $request->owner_id
            ? $this->findUserByIdOrUuid($request->owner_id)
            : $request->user();

        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'Owner not found',
            ], 422);
        }

        try {
            $tenant = DB::connection('mysql_ops')->transaction(function () use ($request, $owner) {
                return Tenant::create([
                    'name' => $request->name,
                    'owner_id' => $owner->uuid,
                    'operation_mode' => $request->operation_mode ?? 'standard',
                ]);
            });

            DB::connection('mysql_auth')->transaction(function () use ($tenant, $owner) {
                // Assign the owner to the tenant
                // If the owner is not already associated with the tenant, attach them
                if (!$tenant->users()->wherePivot('user_id', $owner->uuid)->exists()) {
                    $tenant->users()->attach($owner->uuid, [
                        'role' => 'owner',
                        'assigned_by' => $owner->uuid,
                    ]);
                }

                // Also update the user's current tenant_id if not set
                if (!$owner->tenant_id) {
                    $owner->tenant_id = $tenant->id;
                    $owner->save();
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Tenant created successfully',
                'tenant' => $tenant->load('owner')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create tenant: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $tenant = Tenant::with(['owner', 'stores'])->withCount('users')->findOrFail($id);
        
        // Authorization check could go here
        
        return response()->json([
            'success' => true,
            'tenant' => $tenant
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'owner_id' => 'sometimes|required|string',
            'operation_mode' => 'nullable|in:standard,foodcourt',
        ]);

        $tenant = Tenant::findOrFail($id);

        try {
            DB::beginTransaction();

            $data = $request->only(['name', 'operation_mode']);

            if ($request->has('owner_id')) {
                $newOwner = $this->findUserByIdOrUuid($request->owner_id);

                if (!$newOwner) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Owner not found',
                    ], 422);
                }

                $data['owner_id'] = $newOwner->uuid;
                
                // Ensure new owner is attached to tenant
                if (!$tenant->users()->wherePivot('user_id', $newOwner->uuid)->exists()) {
                    $tenant->users()->attach($newOwner->uuid, ['role' => 'admin']);
                }
                
                // Update owner's current tenant if needed
                if (!$newOwner->tenant_id) {
                    $newOwner->tenant_id = $tenant->id;
                    $newOwner->save();
                }
            }

            $tenant->update($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant updated successfully',
                'tenant' => $tenant->fresh(['owner'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update tenant: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $tenant = Tenant::findOrFail($id);
        
        try {
            $tenant->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Tenant deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's accessible tenants for post-login selection
     */
    public function accessible(Request $request)
    {
        $user = $request->user();
        $user->loadMissing(['tenant', 'tenants', 'ownedTenant']);
        $tenants = collect();

        // Add user's primary tenant
        if ($user->tenant) {
            $tenants->push([
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'is_primary' => true,
                'is_owner' => $this->tenantIsOwnedByUser($user->tenant, $user),
            ]);
        }

        // Add additional accessible tenants via many-to-many relationship
        foreach ($user->tenants as $tenant) {
            // Skip if already added as primary
            if ($tenant->id === $user->tenant_id) {
                continue;
            }

            $tenants->push([
                'id' => $tenant->id,
                'name' => $tenant->name,
                'is_primary' => false,
                'is_owner' => $this->tenantIsOwnedByUser($tenant, $user),
                'role' => $tenant->pivot->role ?? 'employee',
            ]);
        }

        if ($user->ownedTenant && !$tenants->contains('id', $user->ownedTenant->id)) {
            $tenants->push([
                'id' => $user->ownedTenant->id,
                'name' => $user->ownedTenant->name,
                'is_primary' => $user->ownedTenant->id === $user->tenant_id,
                'is_owner' => true,
                'role' => 'owner',
            ]);
        }

        foreach (Tenant::whereIn('owner_id', $this->userOwnerKeys($user))->get() as $tenant) {
            if ($tenants->contains('id', $tenant->id)) {
                continue;
            }

            $tenants->push([
                'id' => $tenant->id,
                'name' => $tenant->name,
                'is_primary' => $tenant->id === $user->tenant_id,
                'is_owner' => true,
                'role' => 'owner',
            ]);
        }

        return response()->json([
            'success' => true,
            'tenants' => $tenants->values(),
        ]);
    }

    private function findUserByIdOrUuid(string $value): ?User
    {
        return User::query()
            ->where('uuid', $value)
            ->orWhere('id', $value)
            ->first();
    }

    /**
     * Auth data should use users.uuid, but keep users.id here so old rows remain visible.
     *
     * @return array<int, string>
     */
    private function userOwnerKeys(User $user): array
    {
        return array_values(array_filter([
            (string) $user->uuid,
            (string) $user->id,
        ]));
    }

    private function tenantIsOwnedByUser(Tenant $tenant, User $user): bool
    {
        return in_array((string) $tenant->owner_id, $this->userOwnerKeys($user), true);
    }
}
