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

        $validated = $request->validate([
            'store_id' => ['nullable', 'uuid', Rule::exists('stores', 'id')],
            'shift_store_id' => ['nullable', 'uuid', Rule::exists('shift_stores', 'id')],
            'status' => ['nullable', Rule::in(Attendance::statuses())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Attendance::query()->with(['store', 'shiftStore', 'creator', 'approver']);

        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            $query->where('created_by_id', $user->id);
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

        $attendances = $query
            ->latest('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        return AttendanceResource::collection($attendances);
    }

    /**
     * Capture a check-in event for the authenticated user.
     */
    public function checkIn(AttendanceCheckInRequest $request): JsonResponse
    {
        $user = $request->user();
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
            'created_by_id' => $user->id,
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

        if ($attendance->created_by_id !== $user->id && ! $user->hasAnyRole(['admin', 'super-admin'])) {
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

        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to approve attendance records.',
            ], 403);
        }

        $status = $request->validated('status');

        $attendance->forceFill([
            'status' => $status,
            'approved_by_id' => $user->id,
        ])->save();

        $attendance->load(['store', 'shiftStore', 'creator', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'Attendance status updated successfully.',
            'attendance' => (new AttendanceResource($attendance))->toArray($request),
        ]);
    }

    private function ensureNoOpenAttendance(User $user): void
    {
        $hasOpenAttendance = Attendance::query()
            ->where('created_by_id', $user->id)
            ->whereNull('check_out')
            ->exists();

        if ($hasOpenAttendance) {
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

        $filename = sprintf('attendance/%s-%s-%s.%s', $prefix, $user->id, Str::uuid(), $extension);

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
}
