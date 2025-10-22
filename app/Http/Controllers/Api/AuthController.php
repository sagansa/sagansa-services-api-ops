<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'tenant_name' => 'nullable|string|max:255',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tenant_id' => null,
            'manager_id' => null,
        ]);

        $tenantName = trim((string) $request->input('tenant_name'));

        if ($tenantName === '') {
            $tenantName = $request->name . "'s Tenant";
        }

        $tenant = Tenant::create([
            'name' => $tenantName,
            'owner_id' => $user->id,
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        $tenant->users()->attach($user->id, [
            'role' => 'owner',
            'assigned_by' => $user->id,
        ]);

        // Log user creation
        \Log::info('User created', ['user_id' => $user->id]);

        // Assign default role (admin for this example) with the correct guard
        $role = Role::where('name', 'admin')->where('guard_name', 'api')->first();
        
        \Log::info('Role lookup result', [
            'role_found' => $role ? true : false,
            'role_data' => $role ? $role->toArray() : null
        ]);
        
        if ($role) {
            try {
                // Assign role to user
                $user->assignRole('admin');
                \Log::info('Role assigned successfully', ['user_id' => $user->id, 'role' => 'admin']);
            } catch (\Exception $e) {
                \Log::error('Error assigning role', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            \Log::warning('Admin role not found during registration');
        }

        // Load fresh user data with roles and tenant meta
        $user->load($this->userRelations());
        
        // Include roles in the response
        $roles = $user->getRoleNames();

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

        // Create token with no expiration for mobile apps
        $token = $user->createToken('mobile-app-token', ['*'], null)->plainTextToken;

        $user->load($this->userRelations());

        // Include roles in the response
        $roles = $user->getRoleNames();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'roles' => $roles,
            'token' => $token,
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
        $user = $request->user()->load($this->userRelations());
        $roles = $user->getRoleNames();

        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    /**
     * Validate existing token for mobile apps
     */
    public function validateToken(Request $request)
    {
        $user = $request->user()->load($this->userRelations());

        
        return response()->json([
            'success' => true,
            'message' => 'Token is valid',
            'user' => $user,
            'roles' => $user->getRoleNames(),
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
                    ->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query
                    ->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ];
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

        $token = $user->createToken('mobile-app-token', ['*'], null)->plainTextToken;
        $user->load($this->userRelations());

        return response()->json([
            'success' => true,
            'message' => 'Undangan berhasil diselesaikan.',
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
