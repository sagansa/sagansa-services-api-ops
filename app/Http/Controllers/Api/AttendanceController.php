<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceCheckInRequest;
use App\Http\Requests\AttendanceCheckOutRequest;
use App\Http\Requests\AttendanceStatusUpdateRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * Display a paginated list of attendance records.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        // Debug logging
        \Log::info('Attendance index request', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'roles' => $user->roles->pluck('name')->toArray(),
            'has_admin_roles' => $user->hasAnyRole(['admin', 'super-admin'])
        ]);

        $validated = $request->validate([
            'store_id' => ['nullable', 'uuid', Rule::exists('stores', 'id')],
            'shift_store_id' => ['nullable', 'uuid', Rule::exists('shift_stores', 'id')],
            'status' => ['nullable', Rule::in(Attendance::statuses())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Attendance::query()->with(['store', 'shiftStore', 'creator', 'approver']);

        // Filter by active tenant if set by middleware
        if ($activeTenantId = $request->attributes->get('current_tenant_id')) {
            $query->whereHas('store', function ($q) use ($activeTenantId) {
                $q->where('tenant_id', $activeTenantId);
            });
        } else {
            // Fallback: All accessible tenants
            $tenantIds = $this->resolveAccessibleTenantIds($user);
            if (! empty($tenantIds)) {
                $query->whereHas('store', function ($q) use ($tenantIds) {
                    $q->whereIn('tenant_id', $tenantIds);
                });
            }
        }

        // Check if user is admin, super-admin, or owner of their tenant
        $isOwner = $user->tenant && $user->tenant->owner_id === $user->id;
        
        if (! $user->hasAnyRole(['admin', 'super-admin']) && !$isOwner) {
            $query->where('created_by_id', $userKey);
            \Log::info('Applied user filter for non-admin user');
        } else {
            \Log::info('No user filter applied for admin/owner user');
        }

        if (! empty($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        if (! empty($validated['shift_store_id'])) {
            $query->where('shift_store_id', $validated['shift_store_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);

        // Debug: Get total count before pagination
        $totalCount = $query->count();
        \Log::info('Attendance query debug', [
            'total_count' => $totalCount,
            'sql' => \DB::getQueryLog()
        ]);

        $attendances = $query
            ->latest('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        \Log::info('Attendance pagination result', [
            'total' => $attendances->total(),
            'per_page' => $attendances->perPage(),
            'current_page' => $attendances->currentPage(),
            'items' => $attendances->items()
        ]);

        return AttendanceResource::collection($attendances);
    }

    /**
     * Capture a check-in event for the authenticated user.
     */
    public function checkIn(AttendanceCheckInRequest $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        $payload = $request->validated();

        $store = Store::findOrFail($payload['store_id']);
        $shiftStore = ShiftStore::findOrFail($payload['shift_store_id']);

        $this->ensureUserCanAccessTenant($user, $store->tenant_id, 'store_id');
        $this->ensureUserCanAccessTenant($user, $shiftStore->tenant_id, 'shift_store_id');
        $this->ensureNoOpenAttendance($user);
        $this->assertStoreHasLocation($store);
        $this->assertWithinAllowedRadius($store, (float) $payload['latitude_in'], (float) $payload['longitude_in'], 'latitude_in');

        $checkInAt = $payload['check_in']
            ? CarbonImmutable::parse($payload['check_in'], config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));

        $imagePath = $this->storeAttendanceImage($payload['image_in'], 'check-in', $user, 'image_in');
        $wasLate = $this->determineLateness($checkInAt, $shiftStore);

        $attendance = Attendance::create([
            'store_id' => $store->id,
            'shift_store_id' => $shiftStore->id,
            'status' => Attendance::STATUS_PENDING,
            'was_late' => $wasLate,
            'image_in' => $imagePath,
            'check_in' => $checkInAt,
            'latitude_in' => $payload['latitude_in'],
            'longitude_in' => $payload['longitude_in'],
            'created_by_id' => $userKey,
        ]);

        $attendance->load(['store', 'shiftStore', 'creator', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Check-in berhasil dicatat.',
            'attendance' => (new AttendanceResource($attendance))->toArray($request),
        ], 201);
    }

    /**
     * Capture a check-out event for the authenticated user.
     */
    public function checkOut(AttendanceCheckOutRequest $request, Attendance $attendance): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        if ($attendance->created_by_id !== $userKey && ! $user->hasAnyRole(['admin', 'super-admin', 'owner'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat melakukan check-out untuk presensi ini.',
            ], 403);
        }

        if ($attendance->check_out) {
            throw ValidationException::withMessages([
                'attendance' => 'Presensi ini sudah melakukan check-out.',
            ]);
        }

        $payload = $request->validated();
        $store = Store::findOrFail($payload['store_id']);

        if ($store->id !== $attendance->store_id) {
            throw ValidationException::withMessages([
                'store_id' => 'Store tidak sesuai dengan presensi yang sedang aktif.',
            ]);
        }

        $this->assertStoreHasLocation($store);
        $this->assertWithinAllowedRadius($store, (float) $payload['latitude_out'], (float) $payload['longitude_out'], 'latitude_out');

        $checkOutAt = $payload['check_out']
            ? CarbonImmutable::parse($payload['check_out'], config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));

        $imagePath = $this->storeAttendanceImage($payload['image_out'], 'check-out', $user, 'image_out');

        $attendance->forceFill([
            'status' => Attendance::STATUS_APPROVED,
            'image_out' => $imagePath,
            'check_out' => $checkOutAt,
            'latitude_out' => $payload['latitude_out'],
            'longitude_out' => $payload['longitude_out'],
            'auto_checked_out_at' => null,
            'approved_by_id' => null,
        ])->save();

        $attendance->load(['store', 'shiftStore', 'creator', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Check-out berhasil dicatat dan tidak memerlukan persetujuan admin.',
            'attendance' => (new AttendanceResource($attendance))->toArray($request),
        ]);
    }

    /**
     * Allow admins to update the approval status of an attendance entry.
     */
    public function updateStatus(AttendanceStatusUpdateRequest $request, Attendance $attendance): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);

        if (! $user->hasAnyRole(['admin', 'super-admin', 'owner'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to approve attendance records.',
            ], 403);
        }

        $status = $request->validated('status');

        $attendance->forceFill([
            'status' => $status,
            'approved_by_id' => $userKey,
        ])->save();

        $attendance->load(['store', 'shiftStore', 'creator', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Attendance status updated successfully.',
            'attendance' => (new AttendanceResource($attendance))->toArray($request),
        ]);
    }

    /**
     * Wrapper for admin-web-next compatibility - returns attendance list with 'data' key
     */
    public function indexCompat(Request $request): JsonResponse
    {
        $attendances = $this->index($request);
        
        // Get the underlying paginator from the resource collection
        $paginator = $attendances->resource;
        
        return response()->json([
            'success' => true,
            'data' => $attendances->map(function ($attendance) use ($request) {
                return (new AttendanceResource($attendance))->toArray($request);
            }),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]
        ]);
    }

    /**
     * Wrapper for admin-web-next compatibility - check-in with simplified response
     */
    public function checkInCompat(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        // Validate data from form data (mobile app compatibility)
        $validated = $request->validate([
            'store_id' => 'required|uuid|exists:stores,id',
            'shift_store_id' => 'nullable|uuid|exists:shift_stores,id',
            'photo' => 'required', // 5MB max
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'check_in' => 'nullable|date',
        ]);

        $store = Store::findOrFail($validated['store_id']);
        $shiftStore = $validated['shift_store_id'] ? ShiftStore::findOrFail($validated['shift_store_id']) : null;

        $this->ensureUserCanAccessTenant($user, $store->tenant_id, 'store_id');
        if ($shiftStore) {
            $this->ensureUserCanAccessTenant($user, $shiftStore->tenant_id, 'shift_store_id');
        }
        $this->ensureNoOpenAttendance($user);
        $this->assertStoreHasLocation($store);
        $this->assertWithinAllowedRadius($store, (float) $validated['latitude'], (float) $validated['longitude'], 'latitude');

        $checkInAt = !empty($validated['check_in'])
            ? CarbonImmutable::parse($validated['check_in'], config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));

        $wasLate = false;
        if ($shiftStore) {
            $wasLate = $this->determineLateness($checkInAt, $shiftStore);
        }

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('presence/selfies', 'public');
        } elseif ($request->filled('photo') && is_string($request->input('photo'))) {
            $photoPath = $request->input('photo');
        }

        // Create attendance record
        $attendance = Attendance::create([
            'store_id' => $validated['store_id'],
            'check_in_store_id' => $validated['store_id'],
            'shift_store_id' => $validated['shift_store_id'],
            'status' => 'pending',
            'presence_type' => 'checkin',
            'image_in' => $photoPath,
            'check_in' => $checkInAt,
            'latitude_in' => $validated['latitude'],
            'longitude_in' => $validated['longitude'],
            'gps_accuracy' => $validated['accuracy'] ?? null,
            'device_info' => null,
            'ip_address' => $request->ip(),
            'is_within_range' => true,
            'distance_to_store' => $this->calculateDistanceInMeters($store->latitude, $store->longitude, (float) $validated['latitude'], (float) $validated['longitude']),
            'was_late' => $wasLate,
            'created_by_id' => $userKey,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Check-in successful',
            'attendance' => (new AttendanceResource($attendance))->toArray($request)
        ], 201);
    }

    /**
     * Wrapper for admin-web-next compatibility - check-out with simplified response
     */
    public function checkOutCompat(Request $request): JsonResponse
    {
        $user = $request->user();
        $userKey = $this->userKey($user);
        
        // Validate attendance_id from form data (mobile app compatibility)
        $validated = $request->validate([
            'attendance_id' => 'required|uuid|exists:attendances,id',
            'store_id' => 'required|uuid|exists:stores,id',
            'photo' => 'nullable', // 5MB max
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'check_out' => 'nullable|date',
        ]);

        // Find the attendance
        $attendance = Attendance::where('id', $validated['attendance_id'])
            ->where('created_by_id', $userKey)
            ->whereNull('check_out')
            ->firstOrFail();

        $store = Store::findOrFail($validated['store_id']);

        $this->assertStoreHasLocation($store);
        $this->assertWithinAllowedRadius($store, (float) $validated['latitude'], (float) $validated['longitude'], 'latitude');

        $checkOutAt = !empty($validated['check_out'])
            ? CarbonImmutable::parse($validated['check_out'], config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('presence/selfies', 'public');
        } elseif ($request->filled('photo') && is_string($request->input('photo'))) {
            $photoPath = $request->input('photo');
        }

        // Update attendance record
        $attendance->update([
            'check_out_store_id' => $validated['store_id'],
            'status' => 'approved',
            'image_out' => $photoPath,
            'check_out' => $checkOutAt,
            'latitude_out' => $validated['latitude'],
            'longitude_out' => $validated['longitude'],
            'gps_accuracy' => $validated['accuracy'] ?? null,
            'distance_to_store' => $this->calculateDistanceInMeters($store->latitude, $store->longitude, (float) $validated['latitude'], (float) $validated['longitude']),
            'is_within_range' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Check-out successful',
            'attendance' => (new AttendanceResource($attendance))->toArray($request)
        ], 200);
    }

    private function ensureNoOpenAttendance(User $user): void
    {
        $openAttendance = Attendance::query()
            ->where('created_by_id', $this->userKey($user))
            ->whereNull('check_out')
            ->first();

        if ($openAttendance) {
            // Check if passed auto-checkout threshold
            $hours = (int) config('attendance.auto_checkout_after_hours', 4);
            $checkInTime = $openAttendance->check_in ?? $openAttendance->created_at;
            
            // If the open attendance is older than the threshold, close it automatically (Lazy Auto-Checkout)
            if ($checkInTime->addHours($hours)->isPast()) {
                $checkOutAt = $checkInTime->addHours($hours);
                
                $openAttendance->forceFill([
                    'status' => Attendance::STATUS_APPROVED,
                    'check_out' => $checkOutAt,
                    'auto_checked_out_at' => now(), // Mark that it was auto-closed now
                    'approved_by_id' => null,
                ])->save();

                \Log::info("Lazy auto-checkout applied for attendance ID: {$openAttendance->id}");
                return; // Allow the new check-in to proceed
            }

            // Otherwise, it's still a valid active session, block check-in
            throw ValidationException::withMessages([
                'attendance' => 'Anda masih memiliki presensi yang belum check-out.',
            ]);
        }
    }

    private function ensureUserCanAccessTenant(User $user, string $tenantId, string $field): void
    {
        if ($this->userBelongsToTenant($user, $tenantId)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Anda tidak memiliki akses ke store atau shift tersebut.',
        ]);
    }

    private function userBelongsToTenant(User $user, string $tenantId): bool
    {
        if ($user->tenant_id === $tenantId) {
            return true;
        }

        if ($user->ownedTenant && $user->ownedTenant->id === $tenantId) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $tenantId)->exists();
    }

    private function assertStoreHasLocation(Store $store): void
    {
        if (is_null($store->latitude) || is_null($store->longitude)) {
            throw ValidationException::withMessages([
                'store_id' => 'Lokasi store belum dikonfigurasi. Hubungi admin terlebih dahulu.',
            ]);
        }
    }

    private function assertWithinAllowedRadius(Store $store, float $latitude, float $longitude, string $field): void
    {
        $distance = $this->calculateDistanceInMeters($store->latitude, $store->longitude, $latitude, $longitude);
        $maximum = (int) config('attendance.max_distance_meters', 150);

        if ($distance > $maximum) {
            throw ValidationException::withMessages([
                $field => sprintf('Lokasi Anda berada di luar radius %d meter dari store.', $maximum),
            ]);
        }
    }

    private function calculateDistanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function determineLateness(CarbonImmutable $checkInAt, ShiftStore $shiftStore): bool
    {
        if (! $shiftStore->shift_start_time) {
            return false;
        }

        try {
            $shiftStart = CarbonImmutable::createFromFormat(
                'Y-m-d H:i',
                $checkInAt->format('Y-m-d') . ' ' . $shiftStore->shift_start_time,
                config('app.timezone')
            );
        } catch (\Throwable $exception) {
            return false;
        }

        return $checkInAt->greaterThan($shiftStart);
    }

    private function storeAttendanceImage(string $rawImage, string $prefix, User $user, string $field): string
    {
        if (filter_var($rawImage, FILTER_VALIDATE_URL)) {
            return $rawImage;
        }

        [$extension, $binary] = $this->decodeBase64Image($rawImage, $field);

        $filename = sprintf('attendance/%s-%s-%s.%s', $prefix, $this->userKey($user), Str::uuid(), $extension);

        Storage::disk('public')->put($filename, $binary);

        return $filename;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function decodeBase64Image(string $rawImage, string $field): array
    {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $rawImage, $matches) === 1) {
            $extension = strtolower($matches[1]);
            $data = base64_decode($matches[2], true);
        } else {
            $extension = 'jpg';
            $data = base64_decode($rawImage, true);
        }

        if (! $data) {
            throw ValidationException::withMessages([
                $field => 'Format gambar tidak valid.',
            ]);
        }

        $extension = $this->normaliseImageExtension($extension, $field);

        return [$extension, $data];
    }

    private function normaliseImageExtension(string $extension, string $field): string
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            default => throw ValidationException::withMessages([
                $field => 'Jenis file gambar tidak didukung. Gunakan JPG atau PNG.',
            ]),
        };
    }

    private function userKey(User $user): string
    {
        return (string) ($user->uuid ?: $user->id);
    }

    /**
     * Resolve the tenant IDs accessible by the authenticated user.
     */
    private function resolveAccessibleTenantIds($user): array
    {
        $tenantIds = collect();

        if (! empty($user->tenant_id)) {
            $tenantIds->push($user->tenant_id);
        }

        if ($user->ownedTenant) {
            $tenantIds->push($user->ownedTenant->id);
        }

        // Check if tenants relationship exists before accessing
        if (method_exists($user, 'tenants')) {
            $membershipTenantIds = $user->tenants()->pluck('tenants.id');
            $tenantIds = $tenantIds->merge($membershipTenantIds);
        }

        return $tenantIds
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
