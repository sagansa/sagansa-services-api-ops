<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * Get stores for the authenticated user's tenant
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $tenantId = $request->attributes->get('current_tenant_id') ?? $user->tenant_id;
        
        // Get stores associated with active tenant
        $stores = Store::where('tenant_id', $tenantId)
            ->select(['id', 'tenant_id', 'store_group_id', 'name', 'nickname', 'email', 'status', 'radius', 'latitude', 'longitude', 'receipt_header', 'receipt_footer', 'email_receipt_logo', 'print_receipt_logo', 'address', 'phone', 'created_at', 'updated_at'])
            ->get();

        return response()->json([
            'success' => true,
            'stores' => $stores
        ]);
    }

    /**
     * Get stores for a specific tenant
     */
    public function indexByTenant(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        
        // Check if user has access to this tenant (either it's their tenant or they're a super admin)
        if ($user->tenant_id !== $tenantId && !$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to tenant'
            ], 403);
        }

        // Get stores associated with the specified tenant
        $stores = Store::where('tenant_id', $tenantId)
            ->select(['id', 'tenant_id', 'store_group_id', 'name', 'nickname', 'email', 'status', 'radius', 'latitude', 'longitude', 'receipt_header', 'receipt_footer', 'email_receipt_logo', 'print_receipt_logo', 'address', 'phone', 'created_at', 'updated_at'])
            ->get();

        return response()->json([
            'success' => true,
            'stores' => $stores
        ]);
    }

    /**
     * Create a new store for the authenticated user's tenant
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $request->validate([
            'name' => 'required|string|max:255',
            'store_group_id' => 'nullable|uuid|exists:store_groups,id',
            'nickname' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|in:active,inactive', // Optional in request but will have default value
            'radius' => 'nullable|integer|min:1', // Optional in request but will have default value
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $tenantId = $request->attributes->get('current_tenant_id') ?? $user->tenant_id;
        $this->ensureOpsTenantExists($tenantId);

        $store = Store::create([
            'tenant_id' => $tenantId,
            'store_group_id' => $request->store_group_id,
            'name' => $request->name,
            'nickname' => $request->nickname,
            'email' => $request->email,
            'status' => $request->status ?? 'active', // Default to 'active' if not provided
            'radius' => $request->radius ?? 100, // Default to 100 meters (as per DB migration) if not provided
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json([
            'success' => true,
            'store' => $store
        ], 201);
    }

    /**
     * Create a new store for a specific tenant
     */
    public function storeByTenant(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        
        // Check if user has access to create stores for this tenant (either it's their tenant or they're a super admin)
        if ($user->tenant_id !== $tenantId && !$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to create stores for this tenant'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'store_group_id' => 'nullable|uuid|exists:store_groups,id',
            'nickname' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|in:active,inactive', // Optional in request but will have default value
            'radius' => 'nullable|integer|min:1', // Optional in request but will have default value
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $this->ensureOpsTenantExists($tenantId);

        $store = Store::create([
            'tenant_id' => $tenantId,
            'store_group_id' => $request->store_group_id,
            'name' => $request->name,
            'nickname' => $request->nickname,
            'email' => $request->email,
            'status' => $request->status ?? 'active', // Default to 'active' if not provided
            'radius' => $request->radius ?? 100, // Default to 100 meters (as per DB migration) if not provided
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json([
            'success' => true,
            'store' => $store
        ], 201);
    }

    /**
     * Get a specific store
     */
    public function show(Request $request, string $storeId): JsonResponse
    {
        $user = $request->user();
        
        $tenantId = $request->attributes->get('current_tenant_id') ?? $user->tenant_id;

        $store = Store::where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->with('paymentMethods')
            ->select(['id', 'tenant_id', 'store_group_id', 'name', 'nickname', 'email', 'status', 'radius', 'latitude', 'longitude', 'tax_rate', 'tax_name'])
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'store' => $store
        ]);
    }

    /**
     * Update a specific store
     */
    public function update(Request $request, string $storeId): JsonResponse
    {
        $user = $request->user();
        
        $tenantId = $request->attributes->get('current_tenant_id') ?? $user->tenant_id;

        $store = Store::where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'store_group_id' => 'nullable|uuid|exists:store_groups,id',
            'nickname' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'status' => 'sometimes|in:active,inactive',
            'radius' => 'nullable|integer|min:1',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_name' => 'nullable|string|max:50',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'service_charge_type' => 'nullable|in:percentage,fixed',
            'service_charge_rate' => 'nullable|numeric|min:0|max:100',
            'service_charge_amount' => 'nullable|numeric|min:0',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'email_receipt_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'print_receipt_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
        ]);

        // Handle logo uploads
        $data = $request->only([
            'name', 'store_group_id', 'nickname', 'email', 'status', 'radius', 'latitude', 'longitude', 
            'tax_rate', 'tax_name', 'service_charge_rate', 'service_charge_amount',
            'receipt_header', 'receipt_footer', 'address', 'phone'
        ]);

        if ($request->hasFile('email_receipt_logo')) {
            $path = $request->file('email_receipt_logo')->store('store-logos', 'public');
            $data['email_receipt_logo'] = $path;
        }

        if ($request->hasFile('print_receipt_logo')) {
            $path = $request->file('print_receipt_logo')->store('store-logos', 'public');
            $data['print_receipt_logo'] = $path;
        }

        $store->update(array_merge(
            $data,
            [
                'tax_type' => $request->tax_type ?? 'exclusive',
                'service_charge_type' => $request->service_charge_type ?? 'percentage',
            ]
        ));

        return response()->json([
            'success' => true,
            'store' => $store
        ]);
    }

    /**
     * Delete a specific store
     */
    public function destroy(Request $request, string $storeId): JsonResponse
    {
        $user = $request->user();
        
        $tenantId = $request->attributes->get('current_tenant_id') ?? $user->tenant_id;

        $store = Store::where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully'
        ]);
    }

    /**
     * Update a store for a specific tenant
     */
    public function updateByTenant(Request $request, string $tenantId, string $storeId): JsonResponse
    {
        $user = $request->user();
        
        if ($user->tenant_id !== $tenantId && !$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update stores for this tenant'
            ], 403);
        }

        $store = Store::where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found or unauthorized'
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'store_group_id' => 'nullable|uuid|exists:store_groups,id',
            'nickname' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'status' => 'sometimes|in:active,inactive',
            'radius' => 'nullable|integer|min:1',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_name' => 'nullable|string|max:50',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'service_charge_type' => 'nullable|in:percentage,fixed',
            'service_charge_rate' => 'nullable|numeric|min:0|max:100',
            'service_charge_amount' => 'nullable|numeric|min:0',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'email_receipt_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'print_receipt_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
        ]);

        // Handle logo uploads
        $data = $request->only([
            'name', 'store_group_id', 'nickname', 'email', 'status', 'radius', 'latitude', 'longitude',
            'tax_rate', 'tax_name', 'service_charge_rate', 'service_charge_amount',
            'receipt_header', 'receipt_footer', 'address', 'phone'
        ]);

        if ($request->hasFile('email_receipt_logo')) {
            $path = $request->file('email_receipt_logo')->store('store-logos', 'public');
            $data['email_receipt_logo'] = $path;
        }

        if ($request->hasFile('print_receipt_logo')) {
            $path = $request->file('print_receipt_logo')->store('store-logos', 'public');
            $data['print_receipt_logo'] = $path;
        }

        $store->update(array_merge(
            $data,
            [
                'tax_type' => $request->tax_type ?? 'exclusive',
                'service_charge_type' => $request->service_charge_type ?? 'percentage',
            ]
        ));

        return response()->json([
            'success' => true,
            'store' => $store
        ]);
    }

    /**
     * Delete a store for a specific tenant
     */
    public function destroyByTenant(Request $request, string $tenantId, string $storeId): JsonResponse
    {
        $user = $request->user();
        
        // Check if user has access to delete stores for this tenant
        if ($user->tenant_id !== $tenantId && !$user->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete stores for this tenant'
            ], 403);
        }

        $store = Store::where('id', $storeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully'
        ]);
    }

    private function ensureOpsTenantExists(?string $tenantId): void
    {
        if (!$tenantId) {
            return;
        }

        $authTenant = DB::connection('mysql_auth')
            ->table('tenants')
            ->where('id', $tenantId)
            ->first();

        if (!$authTenant) {
            return;
        }

        DB::connection('mysql_ops')
            ->table('tenants')
            ->updateOrInsert(
                ['id' => $authTenant->id],
                [
                    'name' => $authTenant->name,
                    'operation_mode' => $authTenant->operation_mode ?? 'standard',
                    'foodcourt_config' => $authTenant->foodcourt_config ?? null,
                    'owner_id' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
    }
}
