<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\ShiftStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters
        
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    /**
     * Get attendance history for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        $attendances = Attendance::where('created_by_id', $userKey)
            ->with([
                'checkInStore:id,name,nickname', 
                'checkOutStore:id,name,nickname', 
                'shiftStore:id,name'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $attendances
        ]);
    }

    /**
     * Get active attendance (belum check-out) untuk user yang sedang login
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        $activeAttendance = Attendance::where('created_by_id', $userKey)
            ->whereNull('check_out')
            ->with([
                'checkInStore:id,name,nickname', 
                'shiftStore:id,name'
            ])
            ->latest()
            ->first();

        if (!$activeAttendance) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada attendance aktif'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $activeAttendance
        ]);
    }

    /**
     * Check in (create attendance record)
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        // Cek apakah user sudah check-in hari ini di store ini
        $todayCheckin = Attendance::where('created_by_id', $userKey)
            ->where('store_id', $request->store_id)
            ->whereDate('check_in', today())
            ->whereNull('check_out')
            ->first();

        if ($todayCheckin) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah check-in hari ini di store ini. Silahkan check-out terlebih dahulu.'
            ], 400);
        }

        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'shift_store_id' => 'required|exists:shift_stores,id',
            'selfie_photo' => 'required',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'gps_accuracy' => 'nullable|numeric|min:0|max:100',
            'device_info' => 'nullable|string|max:255',
        ]);

        // Verify that the store belongs to the user's tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized or store not found'
            ], 403);
        }

        // Validate GPS location
        $distance = $this->calculateDistance(
            $request->latitude,
            $request->longitude,
            $store->latitude,
            $store->longitude
        );

        $isWithinRange = $distance <= 100; // 100 meters radius

        try {
            DB::beginTransaction();

            // Handle selfie photo upload
            $selfiePath = null;
            if ($request->hasFile('selfie_photo')) {
                $selfiePath = $request->file('selfie_photo')->store('presence/selfies', 'public');
            } elseif ($request->filled('selfie_photo') && is_string($request->input('selfie_photo'))) {
                $selfiePath = $request->input('selfie_photo');
            }

            $attendance = Attendance::create([
                'store_id' => $request->store_id,
                'check_in_store_id' => $request->store_id,
                'shift_store_id' => $request->shift_store_id,
                'status' => Attendance::STATUS_PENDING,
                'image_in' => $selfiePath, // Use existing image_in field for selfie
                'check_in' => now(),
                'latitude_in' => $request->latitude,
                'longitude_in' => $request->longitude,
                'gps_accuracy' => $request->gps_accuracy,
                'device_info' => $request->device_info,
                'ip_address' => $request->ip(),
                'is_within_range' => $isWithinRange,
                'distance_to_store' => $distance,
                'created_by_id' => $userKey,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $attendance->load(['store:id,name,nickname', 'shiftStore:id,name']),
                    'location_validation' => [
                        'is_within_range' => $isWithinRange,
                        'distance_to_store' => round($distance, 2),
                        'max_allowed_distance' => 100 // meters
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to check in: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check out (update attendance record)
     */
    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        $request->validate([
            'attendance_id' => 'required|exists:attendances,id',
            'store_id' => 'required|exists:stores,id',
            'selfie_photo' => 'required',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'gps_accuracy' => 'nullable|numeric|min:0|max:100',
            'device_info' => 'nullable|string|max:255',
        ]);

        // Cari attendance yang belum check-out
        $attendance = Attendance::where('id', $request->attendance_id)
            ->where('created_by_id', $userKey)
            ->whereNull('check_out')
            ->first();

        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance record not found or already checked out'
            ], 404);
        }

        // Verify that the store belongs to the user's tenant
        $store = Store::where('id', $request->store_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized or store not found'
            ], 403);
        }

        // Validate GPS location untuk check-out
        $distance = $this->calculateDistance(
            $request->latitude,
            $request->longitude,
            $store->latitude,
            $store->longitude
        );

        $isWithinRange = $distance <= 100; // 100 meters radius

        try {
            // Handle selfie photo upload untuk check-out
            $selfiePath = null;
            if ($request->hasFile('selfie_photo')) {
                $selfiePath = $request->file('selfie_photo')->store('presence/selfies', 'public');
            } elseif ($request->filled('selfie_photo') && is_string($request->input('selfie_photo'))) {
                $selfiePath = $request->input('selfie_photo');
            }

            $attendance->update([
                'check_out_store_id' => $request->store_id,
                'image_out' => $selfiePath, // Use existing image_out field for selfie
                'check_out' => now(),
                'latitude_out' => $request->latitude,
                'longitude_out' => $request->longitude,
                'gps_accuracy' => $request->gps_accuracy,
                'device_info' => $request->device_info,
                'ip_address' => $request->ip(),
                'is_within_range' => $isWithinRange,
                'distance_to_store' => $distance,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $attendance->load(['store:id,name,nickname', 'shiftStore:id,name', 'checkInStore:id,name,nickname', 'checkOutStore:id,name,nickname']),
                    'location_validation' => [
                        'is_within_range' => $isWithinRange,
                        'distance_to_store' => round($distance, 2),
                        'max_allowed_distance' => 100 // meters
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check out: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance details
     */
    public function show(Request $request, string $attendanceId): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        $attendance = Attendance::where('id', $attendanceId)
            ->where('created_by_id', $userKey)
            ->with(['store:id,name,nickname', 'shiftStore:id,name'])
            ->first();

        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance record not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $attendance
        ]);
    }

    private function userKey($user): string
    {
        return (string) ($user->uuid ?: $user->id);
    }
}
