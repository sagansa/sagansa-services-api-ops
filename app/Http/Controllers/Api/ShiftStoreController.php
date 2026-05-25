<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftStoreController extends Controller
{
    /**
     * Get shift stores for a specific tenant
     */
    public function indexByTenant(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        
        // Access validation handled by tenant.context middleware

        // Get shift stores associated with the specified tenant
        $shiftStores = ShiftStore::where('tenant_id', $tenantId)
            ->select(['id', 'name', 'shift_start_time', 'shift_end_time', 'duration'])
            ->get();

        return response()->json([
            'success' => true,
            'shiftStores' => $shiftStores
        ]);
    }

    /**
     * Create a new shift store for a specific tenant
     */
    public function storeByTenant(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        
        // Access validation handled by tenant.context middleware

        $request->validate([
            'name' => 'required|string|max:255',
            'shift_start_time' => 'required|date_format:H:i',
            'shift_end_time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:1',
        ]);

        // Cek apakah sudah ada shift dengan nama yang sama untuk tenant ini
        $existingShift = ShiftStore::where('tenant_id', $tenantId)
            ->where('name', $request->name)
            ->first();

        if ($existingShift) {
            return response()->json([
                'success' => false,
                'message' => 'A shift with this name already exists for this tenant'
            ], 422);
        }

        try {
            $shiftStore = ShiftStore::create([
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'shift_start_time' => $request->shift_start_time,
                'shift_end_time' => $request->shift_end_time,
                'duration' => $request->duration,
            ]);

            return response()->json([
                'success' => true,
                'shiftStore' => $shiftStore,
                'message' => 'Shift created successfully'
            ], 201);
        } catch (\Exception $e) {
            // Tangani error duplikasi database
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'message' => 'A shift with this name already exists for this tenant'
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create shift: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a shift store for a specific tenant
     */
    public function updateByTenant(Request $request, string $tenantId, string $shiftStoreId): JsonResponse
    {
        $user = $request->user();
        
        // Access validation handled by tenant.context middleware

        $shiftStore = ShiftStore::where('tenant_id', $tenantId)->find($shiftStoreId);
        
        if (!$shiftStore) {
            return response()->json([
                'success' => false,
                'message' => 'Shift store not found'
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'shift_start_time' => 'sometimes|date_format:H:i',
            'shift_end_time' => 'sometimes|date_format:H:i',
            'duration' => 'sometimes|integer|min:1',
        ]);

        $shiftStore->update($request->only(['name', 'shift_start_time', 'shift_end_time', 'duration']));

        return response()->json([
            'success' => true,
            'shiftStore' => $shiftStore
        ]);
    }

    /**
     * Delete a shift store for a specific tenant
     */
    public function destroyByTenant(Request $request, string $tenantId, string $shiftStoreId): JsonResponse
    {
        $user = $request->user();
        
        // Access validation handled by tenant.context middleware

        $shiftStore = ShiftStore::where('tenant_id', $tenantId)->find($shiftStoreId);
        
        if (!$shiftStore) {
            return response()->json([
                'success' => false,
                'message' => 'Shift store not found'
            ], 404);
        }

        $shiftStore->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shift store deleted successfully'
        ]);
    }
}