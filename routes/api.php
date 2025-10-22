<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ProductController;
use App\Models\Role;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use App\Notifications\UserInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group. Make something great!
|
*/

if (! function_exists('resolveShiftDuration')) {
    /**
     * Resolve a shift duration in minutes, defaulting to the delta between start and end times.
     */
    function resolveShiftDuration(string $shiftStart, string $shiftEnd, ?int $duration = null): int
    {
        if ($duration !== null && $duration >= 0) {
            return $duration;
        }

        $start = CarbonImmutable::createFromFormat('H:i', $shiftStart) ?: CarbonImmutable::createFromFormat('H:i:s', $shiftStart);
        $end = CarbonImmutable::createFromFormat('H:i', $shiftEnd) ?: CarbonImmutable::createFromFormat('H:i:s', $shiftEnd);

        if (! $start || ! $end) {
            return 0;
        }

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return (int) $start->diffInMinutes($end);
    }
}

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::get('invitations/{token}', [AuthController::class, 'showInvitation']);
    Route::post('invitations/{token}', [AuthController::class, 'completeInvitation']);
    
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::get('validate-token', [AuthController::class, 'validateToken']);
    });
});

// Public product catalogue
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);

// User Management Routes (Protected)
Route::middleware(['auth:sanctum', 'role:admin|super-admin'])->group(function () {
    Route::post('products', [ProductController::class, 'store']);
    Route::put('products/{product}', [ProductController::class, 'update']);
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);

    Route::get('users', function (Request $request) {
        $authUser = $request->user();

        $tenantMemberships = $authUser->tenants()->withPivot('role')->get();

        $accessibleTenantIds = $tenantMemberships
            ->pluck('id')
            ->merge([$authUser->tenant_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $manageableTenantIds = $tenantMemberships
            ->filter(function ($tenant) {
                return in_array($tenant->pivot->role, ['owner', 'admin'], true);
            })
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($authUser->ownedTenant && $authUser->ownedTenant->id) {
            $manageableTenantIds = collect($manageableTenantIds)
                ->push($authUser->ownedTenant->id)
                ->unique()
                ->values()
                ->all();
        }

        $query = User::with([
            'roles:id,name',
            'tenant' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ]);

        if (! $authUser->hasRole('super-admin')) {
            $query->where(function ($inner) use ($manageableTenantIds, $authUser) {
                $inner->where('id', $authUser->id)
                    ->orWhere(function ($eligible) use ($manageableTenantIds) {
                        $eligible->whereIn('tenant_id', $manageableTenantIds)
                            ->orWhereHas('tenants', function ($sub) use ($manageableTenantIds) {
                                $sub->whereIn('tenants.id', $manageableTenantIds)
                                    ->where('tenant_user.role', 'member');
                            });
                    });
            });
        }

        $users = $query->get()->map(function (User $user) use ($authUser, $manageableTenantIds) {
            if (! $authUser->hasRole('super-admin')) {
                if ($user->tenant && ! in_array($user->tenant->id, $manageableTenantIds, true) && $user->id !== $authUser->id) {
                    $user->setRelation('tenant', null);
                }

                $filteredTenants = $user->tenants->filter(function ($tenant) use ($manageableTenantIds) {
                    return in_array($tenant->id, $manageableTenantIds, true);
                })->values();

                $user->setRelation('tenants', $filteredTenants);
            }

            return $user;
        });

        return response()->json([
            'success' => true,
            'users' => $users,
        ]);
    });

    Route::get('users/{id}', function (Request $request, $id) {
        $authUser = $request->user();

        $tenantMemberships = $authUser->tenants()->withPivot('role')->get();

        $accessibleTenantIds = $tenantMemberships
            ->pluck('id')
            ->merge([$authUser->tenant_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $manageableTenantIds = $tenantMemberships
            ->filter(function ($tenant) {
                return in_array($tenant->pivot->role, ['owner', 'admin'], true);
            })
            ->pluck('id')
            ->merge([
                optional($authUser->ownedTenant)->id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = User::with([
            'roles:id,name',
            'tenant' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ]);

        if (! $authUser->hasRole('super-admin')) {
            $query->where(function ($inner) use ($manageableTenantIds, $authUser) {
                $inner->where('id', $authUser->id)
                    ->orWhere(function ($eligible) use ($manageableTenantIds) {
                        $eligible->whereIn('tenant_id', $manageableTenantIds)
                            ->orWhereHas('tenants', function ($sub) use ($manageableTenantIds) {
                                $sub->whereIn('tenants.id', $manageableTenantIds)
                                    ->where('tenant_user.role', 'member');
                            });
                    });
            });
        }

        $user = $query->find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if (! $authUser->hasRole('super-admin')) {
            if ($user->tenant && ! in_array($user->tenant->id, $manageableTenantIds, true) && $user->id !== $authUser->id) {
                $user->setRelation('tenant', null);
            }

            $filteredTenants = $user->tenants->filter(function ($tenant) use ($manageableTenantIds) {
                return in_array($tenant->id, $manageableTenantIds, true);
            })->values();

            $user->setRelation('tenants', $filteredTenants);
        }

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    });

    Route::post('users', function (Request $request) {
        $rules = [
            'user_id' => 'sometimes|uuid|exists:users,id',
            'role' => 'sometimes|string|in:owner,admin,member',
            'email' => 'required_without:user_id|email|max:255',
        ];

        $isSuperAdmin = $request->user()->hasRole('super-admin');

        if ($isSuperAdmin) {
            $rules = array_merge($rules, [
                'name' => 'required_without:user_id|string|max:255',
                'password' => 'required_without:user_id|string|min:8|confirmed',
                'roles' => 'sometimes|array',
                'roles.*' => 'uuid|exists:roles,id',
                'tenant_id' => 'required|uuid|exists:tenants,id',
                'manager_id' => 'nullable|uuid|exists:users,id',
            ]);
        } else {
            $rules['name'] = 'sometimes|string|max:255';
        }

        $authUser = $request->user();

        $tenantMemberships = $authUser->tenants()->withPivot('role')->get();

        $accessibleTenantIds = $tenantMemberships
            ->pluck('id')
            ->merge([$authUser->tenant_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $manageableTenantIds = $tenantMemberships
            ->filter(function ($tenant) {
                return in_array($tenant->pivot->role, ['owner', 'admin'], true);
            })
            ->pluck('id')
            ->merge([optional($authUser->ownedTenant)->id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $data = $request->validate($rules);

        $existingUserId = $data['user_id'] ?? null;
        $membershipRole = $data['role'] ?? ($isSuperAdmin ? 'member' : 'member');
        unset($data['user_id'], $data['role']);

        if (! $isSuperAdmin && empty($existingUserId)) {
            $data['name'] = $data['name'] ?? strtok($data['email'], '@');
        }

        if (! $isSuperAdmin && $membershipRole === 'owner') {
            return response()->json([
                'success' => false,
                'message' => 'Only super-admins can assign owner role.',
            ], 403);
        }

        $assignedRoles = $isSuperAdmin ? ($data['roles'] ?? []) : [];
        unset($data['roles']);

        $tenantId = $data['tenant_id'] ?? null;
        unset($data['tenant_id']);

        if (! $tenantId) {
            $tenantId = $manageableTenantIds[0]
                ?? $authUser->tenant_id
                ?? $accessibleTenantIds[0]
                ?? null;
        }

        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to determine tenant for new user.',
            ], 422);
        }

        $tenant = Tenant::with('owner')->find($tenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 422);
        }

        if (! $isSuperAdmin && ! in_array($tenantId, $manageableTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be an admin or owner of the selected tenant to add users.',
            ], 403);
        }

        $managerId = null;
        if ($isSuperAdmin) {
            $managerId = $data['manager_id'] ?? $tenant->owner_id;
            if ($managerId) {
                $manager = User::where('id', $managerId)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if (! $manager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected manager does not belong to the specified tenant.',
                    ], 422);
                }
            }
        } else {
            $managerId = $request->user()->id;
        }

        unset($data['manager_id']);

        $user = null;

        if ($existingUserId) {
            $user = User::find($existingUserId);
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }
        }

        $invitationSent = false;
        $rawPassword = $data['password'] ?? null;
        unset($data['password'], $data['password_confirmation']);

        if (! $user) {
            $user = User::where('email', $data['email'])->first();
        }

        if (! $user) {
            $passwordForCreation = $isSuperAdmin ? ($rawPassword ?: Str::random(32)) : Str::random(32);

            $user = User::create([
                'name' => $data['name'] ?? strtok($data['email'], '@'),
                'email' => $data['email'],
                'tenant_id' => $isSuperAdmin ? $tenantId : null,
                'manager_id' => $managerId,
                'password' => Hash::make($passwordForCreation),
            ]);

            if (! $isSuperAdmin) {
                $user->forceFill([
                    'invitation_token' => Str::random(64),
                    'invitation_token_expires_at' => CarbonImmutable::now()->addDays(7),
                    'invited_at' => CarbonImmutable::now(),
                    'invited_by' => $request->user()->id,
                ])->save();

                $user->notify(new UserInvitationNotification(
                    tenant: $tenant,
                    token: $user->invitation_token,
                    inviterName: $request->user()->name,
                ));

                $invitationSent = true;
            }
        } else {
            if ($managerId && $user->id !== $managerId) {
                $user->manager_id = $managerId;
                $user->save();
            }
        }

        $tenant->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $membershipRole,
                'assigned_by' => $request->user()->id,
            ],
        ]);

        if (! empty($assignedRoles)) {
            $roleNames = Role::whereIn('id', $assignedRoles)->pluck('name')->all();
            $user->syncRoles($roleNames);
        }

        $user->load([
            'roles:id,name',
            'tenant' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => $invitationSent ? 'User invited successfully' : 'User created successfully',
            'user' => $user,
            'invitation_sent' => $invitationSent,
        ], 201);
    });
    Route::put('users/{id}', function (Request $request, $id) {
        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $isSuperAdmin = $request->user()->hasRole('super-admin');

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8|confirmed',
            'roles' => 'sometimes|array',
            'roles.*' => 'uuid|exists:roles,id',
            'tenant_id' => 'sometimes|uuid|exists:tenants,id',
            'membership_role' => 'sometimes|string|in:owner,admin,member',
        ];

        if ($isSuperAdmin) {
            $rules['manager_id'] = 'nullable|uuid|exists:users,id';
        }

        $data = $request->validate($rules);

        $assignedRoles = $isSuperAdmin ? ($data['roles'] ?? null) : null;
        unset($data['roles']);

        $membershipRole = $data['membership_role'] ?? null;
        unset($data['membership_role']);

        $managerId = $user->manager_id;
        if ($isSuperAdmin && array_key_exists('manager_id', $data)) {
            $managerId = $data['manager_id'];
        }
        unset($data['manager_id']);

        $authUser = $request->user();
        $tenantMemberships = $authUser->tenants()->withPivot('role')->get();

        $accessibleTenantIds = $tenantMemberships
            ->pluck('id')
            ->merge([$authUser->tenant_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $manageableTenantIds = $tenantMemberships
            ->filter(function ($tenant) {
                return in_array($tenant->pivot->role, ['owner', 'admin'], true);
            })
            ->pluck('id')
            ->merge([
                optional($authUser->ownedTenant)->id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $tenantId = $user->tenant_id;
        if (array_key_exists('tenant_id', $data)) {
            $tenantId = $data['tenant_id'];
        }
        unset($data['tenant_id']);

        $tenant = $tenantId ? Tenant::with('owner')->find($tenantId) : null;
        if ($tenantId && !$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found.',
            ], 422);
        }

        if (!$isSuperAdmin && $tenantId && !in_array($tenantId, $manageableTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be an admin or owner of the selected tenant to update users.',
            ], 403);
        }

        if (!$isSuperAdmin) {
            $managerId = $authUser->id;
        } elseif ($tenant && $tenantId !== $user->tenant_id && !$membershipRole) {
            $membershipRole = 'member';
        }

        if ($managerId && $tenantId) {
            $manager = User::where('id', $managerId)
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhereHas('tenants', function ($sub) use ($tenantId) {
                            $sub->where('tenants.id', $tenantId);
                        });
                })
                ->first();

            if (!$manager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected manager does not belong to the specified tenant.',
                ], 422);
            }
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        unset($data['password_confirmation']);

        $user->fill($data);
        $user->tenant_id = $tenantId;
        $user->manager_id = $managerId;
        $user->save();

        if ($assignedRoles !== null) {
            $roleNames = Role::whereIn('id', $assignedRoles)->pluck('name')->all();
            $user->syncRoles($roleNames);
        }

        if ($tenantId) {
            $tenant->users()->syncWithoutDetaching([
                $user->id => [
                    'role' => $membershipRole ?? ($tenant->owner_id === $user->id ? 'owner' : 'admin'),
                    'assigned_by' => $authUser->id,
                ],
            ]);
        }

        $effectiveMembershipRole = $membershipRole ?? ($tenant && $tenant->owner_id === $user->id ? 'owner' : 'admin');

        if ($tenantId) {
            $user->tenants()->updateExistingPivot($tenantId, [
                'role' => $effectiveMembershipRole,
            ]);
        }

        $user->load([
            'roles:id,name',
            'tenant' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
            'tenants' => function ($query) {
                $query->withCount('users')
                    ->with([
                        'owner:id,name,email',
                        'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
                        'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
                    ]);
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    });

    Route::delete('users/{id}', function (Request $request, $id) {
        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = User::query();

        if (!$authUser->hasRole('super-admin')) {
            $query->where(function ($inner) use ($accessibleTenantIds) {
                $inner->whereIn('tenant_id', $accessibleTenantIds)
                    ->orWhereHas('tenants', function ($sub) use ($accessibleTenantIds) {
                        $sub->whereIn('tenants.id', $accessibleTenantIds);
                    });
            });
        }

        $user = $query->find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    });

    Route::get('tenants/{tenantId}/stores', function (Request $request, $tenantId) {
        $tenant = Tenant::with([
            'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
            'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
        ])->find($tenantId);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$authUser->hasRole('super-admin') && !in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to access stores for this tenant.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'stores' => $tenant->stores,
        ]);
    });

    Route::post('tenants/{tenantId}/stores', function (Request $request, $tenantId) {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$authUser->hasRole('super-admin') && !in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to create stores for this tenant.',
            ], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'radius' => 'nullable|numeric|min:0',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        foreach (['latitude', 'longitude'] as $coordinate) {
            if (array_key_exists($coordinate, $data)) {
                $data[$coordinate] = $data[$coordinate] === null ? null : (float) $data[$coordinate];
            }
        }

        if (array_key_exists('radius', $data)) {
            $data['radius'] = $data['radius'] === null ? null : (int) $data['radius'];
        }

        $store = $tenant->stores()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Store created successfully',
            'store' => $store,
        ], 201);
    });

    Route::put('tenants/{tenantId}/stores/{storeId}', function (Request $request, $tenantId, $storeId) {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$authUser->hasRole('super-admin') && !in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to update stores for this tenant.',
            ], 403);
        }

        $store = Store::where('tenant_id', $tenant->id)->where('id', $storeId)->first();
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'nickname' => 'sometimes|nullable|string|max:255',
            'no_telp' => 'sometimes|nullable|string|max:50',
            'email' => 'sometimes|nullable|email|max:255',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'radius' => 'sometimes|nullable|numeric|min:0',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        foreach (['latitude', 'longitude'] as $coordinate) {
            if (array_key_exists($coordinate, $data)) {
                $data[$coordinate] = $data[$coordinate] === null ? null : (float) $data[$coordinate];
            }
        }

        if (array_key_exists('radius', $data)) {
            $data['radius'] = $data['radius'] === null ? null : (int) $data['radius'];
        }

        $store->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'store' => $store,
        ]);
    });

    Route::delete('tenants/{tenantId}/stores/{storeId}', function (Request $request, $tenantId, $storeId) {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$authUser->hasRole('super-admin') && !in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to delete stores for this tenant.',
            ], 403);
        }

        $store = Store::where('tenant_id', $tenant->id)->where('id', $storeId)->first();
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully',
        ]);
    });

    Route::get('tenants/{tenantId}/shift-stores', function (Request $request, $tenantId) {
        $tenant = Tenant::with(['shiftStores' => function ($query) {
            $query->orderBy('shift_start_time');
        }])->find($tenantId);

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $authUser->hasRole('super-admin') && ! in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to access shifts for this tenant.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'shift_stores' => $tenant->shiftStores,
        ]);
    });

    Route::post('tenants/{tenantId}/shift-stores', function (Request $request, $tenantId) {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $authUser->hasRole('super-admin') && ! in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to create shifts for this tenant.',
            ], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shift_start_time' => 'required|date_format:H:i',
            'shift_end_time' => 'required|date_format:H:i',
            'duration' => 'nullable|integer|min:0',
        ]);

        $data['duration'] = resolveShiftDuration($data['shift_start_time'], $data['shift_end_time'], $data['duration']);

        $shiftStore = $tenant->shiftStores()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Shift created successfully',
            'shift_store' => $shiftStore,
        ], 201);
    });

    Route::put('tenants/{tenantId}/shift-stores/{shiftStoreId}', function (Request $request, $tenantId, $shiftStoreId) {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $shiftStore = ShiftStore::where('tenant_id', $tenantId)->where('id', $shiftStoreId)->first();
        if (! $shiftStore) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $authUser->hasRole('super-admin') && ! in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to update shifts for this tenant.',
            ], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'shift_start_time' => 'sometimes|date_format:H:i',
            'shift_end_time' => 'sometimes|date_format:H:i',
            'duration' => 'nullable|integer|min:0',
        ]);

        if (array_key_exists('shift_start_time', $data) || array_key_exists('shift_end_time', $data) || array_key_exists('duration', $data)) {
            $start = $data['shift_start_time'] ?? $shiftStore->shift_start_time;
            $end = $data['shift_end_time'] ?? $shiftStore->shift_end_time;
            $duration = $data['duration'] ?? null;

            $data['duration'] = resolveShiftDuration($start, $end, $duration);
        }

        $shiftStore->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Shift updated successfully',
            'shift_store' => $shiftStore->fresh(),
        ]);
    });

    Route::delete('tenants/{tenantId}/shift-stores/{shiftStoreId}', function (Request $request, $tenantId, $shiftStoreId) {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $shiftStore = ShiftStore::where('tenant_id', $tenantId)->where('id', $shiftStoreId)->first();
        if (! $shiftStore) {
            return response()->json([
                'success' => false,
                'message' => 'Shift not found',
            ], 404);
        }

        $authUser = $request->user();
        $accessibleTenantIds = collect($authUser->tenants()->pluck('tenants.id')->toArray())
            ->push($authUser->tenant_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $authUser->hasRole('super-admin') && ! in_array($tenant->id, $accessibleTenantIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to delete shifts for this tenant.',
            ], 403);
        }

        $shiftStore->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shift deleted successfully',
        ]);
    });
});

// Role Management Routes (Super Admin only)
Route::middleware(['auth:sanctum', 'role:super-admin'])->group(function () {
    Route::get('tenants', function () {
        $tenants = Tenant::with([
            'owner:id,name,email',
            'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
        ])->withCount('users')->get();

        return response()->json([
            'success' => true,
            'tenants' => $tenants,
        ]);
    });

    Route::post('tenants', function () {
        $data = request()->validate([
            'name' => 'required|string|max:255|unique:tenants,name',
            'owner_id' => 'required|uuid|exists:users,id',
        ]);

        $owner = User::find($data['owner_id']);

        if ($owner->ownedTenant && $owner->ownedTenant->id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Selected owner already manages another tenant.',
            ], 422);
        }

        $tenant = Tenant::create([
            'name' => $data['name'],
            'owner_id' => $owner->id,
        ]);

        $owner->tenant_id = $tenant->id;
        $owner->manager_id = null;
        $owner->save();

        $tenant->load([
            'owner:id,name,email',
            'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
            'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
        ])->loadCount('users');

        return response()->json([
            'success' => true,
            'message' => 'Tenant created successfully',
            'tenant' => $tenant,
        ], 201);
    });

    Route::get('tenants/{id}', function ($id) {
        $tenant = Tenant::with([
            'owner:id,name,email',
            'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
        ])->withCount('users')->find($id);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'tenant' => $tenant,
        ]);
    });

    Route::put('tenants/{id}', function ($id) {
        $tenant = Tenant::find($id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $data = request()->validate([
            'name' => 'sometimes|string|max:255|unique:tenants,name,' . $id,
            'owner_id' => 'sometimes|uuid|exists:users,id',
        ]);

        if (array_key_exists('name', $data)) {
            $tenant->name = $data['name'];
        }

        if (array_key_exists('owner_id', $data) && $data['owner_id'] !== $tenant->owner_id) {
            $newOwner = User::find($data['owner_id']);

            if ($newOwner->ownedTenant && $newOwner->ownedTenant->id !== $tenant->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected owner already manages another tenant.',
                ], 422);
            }

            $previousOwnerId = $tenant->owner_id;

            $tenant->owner_id = $newOwner->id;
            $tenant->save();

            $newOwner->tenant_id = $tenant->id;
            $newOwner->manager_id = null;
            $newOwner->save();

            if ($previousOwnerId && $previousOwnerId !== $newOwner->id) {
                $previousOwner = User::find($previousOwnerId);
                if ($previousOwner) {
                    $previousOwner->tenant_id = null;
                    $previousOwner->save();
                }
            }
        } else {
            $tenant->save();
        }

        $tenant->load([
            'owner:id,name,email',
            'stores:id,tenant_id,name,nickname,no_telp,email,status,radius,latitude,longitude',
            'shiftStores:id,tenant_id,name,shift_start_time,shift_end_time,duration',
        ])->loadCount('users');

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant,
        ]);
    });

    Route::delete('tenants/{id}', function ($id) {
        $tenant = Tenant::find($id);
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        if ($tenant->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant cannot be deleted while users are assigned.',
            ], 422);
        }

        $owner = $tenant->owner;
        $tenant->delete();

        if ($owner) {
            $owner->tenant_id = null;
            $owner->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully',
        ]);
    });

    Route::get('roles', function () {
        return response()->json([
            'success' => true,
            'roles' => Role::with('permissions:id,name')->get(),
        ]);
    });
    
    Route::post('roles', function () {
        $validatedData = request()->validate([
            'name' => 'required|string|unique:roles,name',
        ]);
        
        $role = Role::create([
            'name' => $validatedData['name'],
            'guard_name' => 'api'
        ])->load('permissions:id,name');
        
        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'role' => $role,
        ], 201);
    });
    
    Route::get('roles/{id}', function ($id) {
        $role = Role::with('permissions:id,name')->find($id);
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'role' => $role,
        ]);
    });
    
    Route::put('roles/{id}', function ($id) {
        $role = Role::find($id);
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 404);
        }
        
        $validatedData = request()->validate([
            'name' => 'sometimes|string|unique:roles,name,'.$id,
        ]);
        
        $role->update($validatedData);
        
        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'role' => $role->load('permissions:id,name'),
        ]);
    });
    
    Route::delete('roles/{id}', function ($id) {
        $role = Role::find($id);
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 404);
        }
        
        $role->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    });
});

// Permission Management Routes (Super Admin only)
Route::middleware(['auth:sanctum', 'role:super-admin'])->group(function () {
    Route::get('permissions', function () {
        return response()->json([
            'success' => true,
            'permissions' => \App\Models\Permission::all(),
        ]);
    });
    
    Route::post('permissions', function () {
        $validatedData = request()->validate([
            'name' => 'required|string|unique:permissions,name',
        ]);
        
        $permission = \App\Models\Permission::create([
            'name' => $validatedData['name'],
            'guard_name' => 'api'
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully',
            'permission' => $permission,
        ], 201);
    });
    
    Route::get('permissions/{id}', function ($id) {
        $permission = \App\Models\Permission::find($id);
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'permission' => $permission,
        ]);
    });
    
    Route::put('permissions/{id}', function ($id) {
        $permission = \App\Models\Permission::find($id);
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);
        }
        
        $validatedData = request()->validate([
            'name' => 'sometimes|string|unique:permissions,name,'.$id,
        ]);
        
        $permission->update($validatedData);
        
        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully',
            'permission' => $permission,
        ]);
    });
    
    Route::delete('permissions/{id}', function ($id) {
        $permission = \App\Models\Permission::find($id);
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);
        }
        
        $permission->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully',
        ]);
    });
    
    // Assign permission to role
    Route::post('roles/{roleId}/permissions/{permissionId}', function ($roleId, $permissionId) {
        $role = \App\Models\Role::find($roleId);
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 404);
        }
        
        $permission = \App\Models\Permission::find($permissionId);
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);
        }
        
        $role->givePermissionTo($permission);
        
        return response()->json([
            'success' => true,
            'message' => 'Permission assigned to role successfully',
        ]);
    });
    
    // Remove permission from role
    Route::delete('roles/{roleId}/permissions/{permissionId}', function ($roleId, $permissionId) {
        $role = \App\Models\Role::find($roleId);
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
            ], 404);
        }
        
        $permission = \App\Models\Permission::find($permissionId);
        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);
        }
        
        $role->revokePermissionTo($permission);
        
        return response()->json([
            'success' => true,
            'message' => 'Permission removed from role successfully',
        ]);
    });
});

// Attendance Routes
Route::middleware('auth:sanctum')->prefix('attendance')->group(function () {
    Route::get('/', [AttendanceController::class, 'index']);
    Route::post('check-in', [AttendanceController::class, 'checkIn']);
    Route::post('{attendance}/check-out', [AttendanceController::class, 'checkOut']);
    Route::patch('{attendance}/status', [AttendanceController::class, 'updateStatus']);
});
