<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Store;
use App\Models\ShiftStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresenceController extends ApiController
{
    /**
     * Display a listing of attendances for oversight.
     */
    public function index(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Attendance::whereHas('store', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->with(['store', 'shiftStore', 'creator', 'approver']);
        
        // Add optional filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('check_in', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('check_in', '<=', $request->date_to);
        }
        
        $attendances = $query->paginate(10);

        return response()->json($attendances);
    }

    /**
     * Store a newly created attendance record.
     */
    public function store(Request $request)
    {
        $userKey = $this->userKey($request->user());

        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'shift_store_id' => 'required|exists:shift_stores,id',
            'image_in' => 'required',
            'check_in' => 'required|date',
            'latitude_in' => 'required|numeric',
            'longitude_in' => 'required|numeric',
        ]);

        $tenantId = $this->currentTenantId();
        
        // Verify that the store belongs to the current tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $tenantId)
            ->first();
        
        if (!$store) {
            return response()->json(['error' => 'Unauthorized or store not found'], 403);
        }

        try {
            DB::beginTransaction();

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image_in')) {
                $imagePath = app(\App\Contracts\ImageStorageContract::class)->upload($request->file('image_in'), 'attendances');
            } elseif ($request->filled('image_in') && is_string($request->input('image_in'))) {
                $imagePath = $request->input('image_in');
            }

            $attendance = Attendance::create([
                'store_id' => $request->store_id,
                'shift_store_id' => $request->shift_store_id,
                'status' => Attendance::STATUS_PENDING, // Default to pending for admin approval
                'image_in' => $imagePath,
                'check_in' => $request->check_in,
                'latitude_in' => $request->latitude_in,
                'longitude_in' => $request->longitude_in,
                'created_by_id' => $userKey,
            ]);

            DB::commit();

            return response()->json($attendance->load(['store', 'shiftStore', 'creator', 'approver']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($imagePath)) {
                app(\App\Contracts\ImageStorageContract::class)->delete($imagePath);
            }
            return response()->json(['error' => 'Failed to create attendance record'], 500);
        }
    }

    /**
     * Display the specified attendance.
     */
    public function show(Attendance $attendance)
    {
        $tenantId = $this->currentTenantId();
        
        // Check if the attendance's store belongs to the current tenant
        if ($attendance->store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json($attendance->load(['store', 'shiftStore', 'creator', 'approver']));
    }

    /**
     * Update an attendance record (for approval/rejection).
     */
    public function update(Request $request, Attendance $attendance)
    {
        $userKey = $this->userKey($request->user());
        $tenantId = $this->currentTenantId();
        
        if ($attendance->store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'required|in:pending,approved,rejected',
            'image_out' => 'nullable',
            'check_out' => 'nullable|date',
            'latitude_out' => 'nullable|numeric',
            'longitude_out' => 'nullable|numeric',
        ]);

        try {
            DB::beginTransaction();

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image_out')) {
                if ($attendance->image_out) {
                    app(\App\Contracts\ImageStorageContract::class)->delete($attendance->image_out);
                }
                $imagePath = app(\App\Contracts\ImageStorageContract::class)->upload($request->file('image_out'), 'attendances');
            } elseif ($request->filled('image_out') && is_string($request->input('image_out'))) {
                $imagePath = $request->input('image_out');
            }

            $updateData = $request->only(['status', 'check_out', 'latitude_out', 'longitude_out']);
            
            if ($request->status !== Attendance::STATUS_PENDING) {
                // Only update approval info if status is changed from pending
                $updateData['approved_by_id'] = $userKey;
            }
            
            if ($imagePath) {
                $updateData['image_out'] = $imagePath;
            }

            $attendance->update($updateData);

            DB::commit();

            return response()->json($attendance->load(['store', 'shiftStore', 'creator', 'approver']));
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($imagePath)) {
                app(\App\Contracts\ImageStorageContract::class)->delete($imagePath);
            }
            return response()->json(['error' => 'Failed to update attendance record'], 500);
        }
    }

    /**
     * Remove the specified attendance from storage.
     */
    public function destroy(Attendance $attendance)
    {
        $tenantId = $this->currentTenantId();
        
        if ($attendance->store->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $attendance->delete();
        return response()->json(['message' => 'Attendance record deleted successfully']);
    }
    
    /**
     * Export attendance data.
     */
    public function export(Request $request)
    {
        $tenantId = $this->currentTenantId();
        
        $query = Attendance::whereHas('store', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->with(['store', 'shiftStore', 'creator', 'approver']);
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('check_in', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('check_in', '<=', $request->date_to);
        }
        
        $attendances = $query->get();

        // Create CSV response
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="attendances.csv"',
        ];

        $callback = function () use ($attendances) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID', 'Store', 'Shift', 'Status', 'Check In', 'Check Out', 
                'Late', 'Created By', 'Approved By', 'Created At', 'Updated At'
            ]);

            foreach ($attendances as $attendance) {
                fputcsv($file, [
                    $attendance->id,
                    $attendance->store->name ?? '',
                    $attendance->shiftStore->name ?? '',
                    $attendance->status,
                    $attendance->check_in,
                    $attendance->check_out,
                    $attendance->was_late ? 'Yes' : 'No',
                    $attendance->creator->name ?? '',
                    $attendance->approver->name ?? '',
                    $attendance->created_at,
                    $attendance->updated_at
                ]);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function userKey($user): string
    {
        return (string) ($user->uuid ?: $user->id);
    }
}
