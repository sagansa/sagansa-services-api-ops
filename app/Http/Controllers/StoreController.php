<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Http\Request;

class StoreController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $stores = Store::where('tenant_id', $tenantId)
            ->with('tenant')
            ->paginate(10);

        return response()->json($stores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'status' => 'required|in:active,inactive',
            'radius' => 'required|integer|min:1',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $tenantId = $this->currentTenantId();
        
        $store = Store::create(array_merge(
            $request->only([
                'name', 'nickname', 'no_telp', 'email', 
                'status', 'radius', 'latitude', 'longitude'
            ]),
            ['tenant_id' => $tenantId]
        ));

        return response()->json($store->load('tenant'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Store $store)
    {
        $tenantId = $this->currentTenantId();
        
        // Ensure the store belongs to the current tenant
        if ($store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($store->load('tenant', 'products'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Store $store)
    {
        $tenantId = $this->currentTenantId();
        
        if ($store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'status' => 'sometimes|in:active,inactive',
            'radius' => 'sometimes|integer|min:1',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
        ]);

        $store->update($request->only([
            'name', 'nickname', 'no_telp', 'email', 
            'status', 'radius', 'latitude', 'longitude'
        ]));

        return response()->json($store->load('tenant'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Store $store)
    {
        $tenantId = $this->currentTenantId();
        
        if ($store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $store->delete();
        return response()->json(['message' => 'Store deleted successfully']);
    }
}