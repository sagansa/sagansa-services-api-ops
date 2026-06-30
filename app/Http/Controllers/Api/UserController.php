<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of users
     * Optimized: avoid N+1 from $appends accessor and $with eager-loading.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can access this endpoint'
            ], 403);
        }

        $perPage = (int) $request->get('per_page', 25);

        // Build query WITHOUT auto-eager-load detail and WITHOUT $appends accessor
        $query = User::query()
            ->select('id', 'uuid', 'name', 'email', 'tenant_id', 'is_active', 'created_at')
            ->without(['detail'])         // Skip cross-DB UserDetail eager-load
            ->setAppends([]);             // Skip N+1 last_active_at token query

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->get('role'));
            });
        }

        if ($tenantId = $request->get('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        $users = $query->orderByDesc('created_at')->paginate($perPage);

        // Load relationships ONLY for the paginated items
        $items = $users->getCollection();
        $items->load([
            'roles:id,name',
            'tenants' => function ($q) {
                $q->select('tenants.id', 'tenants.name', 'tenants.operation_mode', 'tenants.owner_id')
                  ->withPivot(['role', 'assigned_by']);
            },
        ]);

        return response()->json([
            'success' => true,
            'users' => $items->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'total_pages' => $users->lastPage(),
                'total_items' => $users->total(),
                'per_page' => $users->perPage(),
            ]
        ]);
    }

    /**
     * Display the specified user
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        // Only super-admin can access global users endpoint
        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can access this endpoint'
            ], 403);
        }
        
        // Check permission
        if (!$user->can('access-backoffice')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view users'
            ], 403);
        }
        
        $targetUser = $this->findUserByKey($id, ['tenant', 'roles', 'permissions']);
        
        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
        
        // Check access
        if (!$user->hasRole('super-admin') && $targetUser->tenant_id !== $user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this user'
            ], 403);
        }
        
        return response()->json([
            'success' => true,
            'user' => $targetUser
        ]);
    }

    /**
     * Create a new user
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Only super-admin can create global users
        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can access this endpoint'
            ], 403);
        }
        
        // Check permission
        if (!$user->can('access-backoffice')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to create users'
            ], 403);
        }
        
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mysql_auth.users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:mysql_auth.roles,name',
        ];
        
        // Super admin can assign any tenant
        if ($user->hasRole('super-admin')) {
            $rules['tenant_id'] = 'required|uuid|exists:mysql_ops.tenants,id';
        } else {
            // Regular admin can only create users in their own tenant
            $request->merge(['tenant_id' => $user->tenant_id]);
        }
        
        $validated = $request->validate($rules);
        
        // Create user
        $newUser = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $validated['tenant_id'],
        ]);
        
        // Assign role
        $newUser->assignRole($validated['role']);
        
        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => $newUser->load(['tenant', 'roles'])
        ], 201);
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        // Only super-admin can update global users
        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can access this endpoint'
            ], 403);
        }
        
        // Check permission
        if (!$user->can('access-backoffice')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update users'
            ], 403);
        }
        
        $targetUser = $this->findUserByKey($id);
        
        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
        
        // Check access
        if (!$user->hasRole('super-admin') && $targetUser->tenant_id !== $user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this user'
            ], 403);
        }
        
        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('mysql_auth.users')->ignore($targetUser->id)],
            'password' => 'sometimes|required|string|min:8',
            'role' => 'sometimes|required|string|exists:mysql_auth.roles,name',
        ];
        
        // Super admin can change tenant and role
        if ($user->hasRole('super-admin')) {
            $rules['tenant_id'] = 'sometimes|required|uuid|exists:mysql_ops.tenants,id';
        }
        
        $validated = $request->validate($rules);

        if (!$user->hasRole('super-admin') && isset($validated['role']) && $validated['role'] === 'super-admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to assign super-admin role'
            ], 403);
        }
        
        // Update user
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        
        $targetUser->update($validated);
        
        // Update role if provided
        if (isset($validated['role'])) {
            $targetUser->syncRoles([$validated['role']]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $targetUser->load(['tenant', 'roles'])
        ]);
    }

    /**
     * Delete the specified user
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        // Only super-admin can delete global users
        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can access this endpoint'
            ], 403);
        }
        
        // Check permission
        if (!$user->can('access-backoffice')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete users'
            ], 403);
        }
        
        $targetUser = $this->findUserByKey($id);
        
        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
        
        // Check access
        if (!$user->hasRole('super-admin') && $targetUser->tenant_id !== $user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete this user'
            ], 403);
        }
        
        // Prevent deleting yourself
        if ($targetUser->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account'
            ], 400);
        }
        
        $targetUser->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
    /**
     * Toggle user active status
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        // Only super-admin can toggle status
        if (!$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only super-admin can perform this action'
            ], 403);
        }
        
        $targetUser = $this->findUserByKey($id);
        
        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
        
        // Prevent deactivating yourself
        if (($targetUser->uuid ?: $targetUser->id) === ($user->uuid ?: $user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change status of your own account'
            ], 400);
        }
        
        $targetUser->is_active = !$targetUser->is_active;
        $targetUser->save();
        
        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'user' => $targetUser
        ]);
    }

    private function findUserByKey(string $key, array $with = []): ?User
    {
        return User::query()
            ->with($with)
            ->where('uuid', $key)
            ->orWhere('id', $key)
            ->first();
    }
}
